<?php

namespace Spatie\MediaLibrary;

use Spatie\MediaLibrary\Conversion\Conversion;
use Spatie\MediaLibrary\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\Exceptions\FileTooBig;
use Spatie\MediaLibrary\Exceptions\MediaDoesNotBelongToModel;
use Spatie\MediaLibrary\Exceptions\MediaIsNotPartOfCollection;

trait HasMediaTrait
{
    /**
     * @var array
     */
    public $mediaConversions = [];

    /**
     * Set the polymorphic relation.
     *
     * @return mixed
     */
    public function media()
    {
        return $this->morphMany(Media::class, 'model');
    }

    /**
     * Add media to media collection from a given file.
     *
     * @param string $file
     * @param string $collectionName
     * @param bool   $removeOriginal
     * @param bool   $addAsTemporary
     *
     * @return Media
     *
     * @throws \Spatie\MediaLibrary\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\Exceptions\FileTooBig
     */
    public function addMedia($file, $collectionName = 'default', $removeOriginal = true, $addAsTemporary = false)
    {
        if (!is_file($file)) {
            throw new FileDoesNotExist();
        }

        if (filesize($file) > config('laravel-medialibrary.max_file_size')) {
            throw new FileTooBig();
        }

        $media = new Media();

        $media->name = pathinfo($file, PATHINFO_FILENAME);
        $media->file_name = pathinfo($file, PATHINFO_BASENAME);

        $media->collection_name = $collectionName;

        $media->size = filesize($file);
        $media->temp = $addAsTemporary;
        $media->manipulations = [];

        $media->save();

        $this->media()->save($media);

        app(Filesystem::class)->add($file, $media);

        if ($removeOriginal) {
            unlink($file);
        }

        return $media;
    }

    /**
     * Get media collection by its collectionName.
     *
     * @param string $collectionName
     * @param array  $filters
     *
     * @return \Illuminate\Support\Collection
     */
    public function getMedia($collectionName = 'default', $filters = ['temp' => 0])
    {
        return app(MediaRepository::class)->getCollection($this, $collectionName, $filters);
    }

    /**
     * Get the first media item of a media collection.
     *
     * @param string $collectionName
     * @param array  $filters
     *
     * @return bool|Media
     */
    public function getFirstMedia($collectionName = 'default', $filters = [])
    {
        $media = $this->getMedia($collectionName, $filters);

        return (count($media) ? $media->first() : false);
    }

    /**
     * Get the url of the image for the given conversionName
     * for first media for the given collectionName.
     * If no profile is given, return the source's url.
     *
     * @param string $collectionName
     * @param string $conversionName
     *
     * @return string
     */
    public function getFirstMediaUrl($collectionName = 'default', $conversionName = '')
    {
        $media = $this->getFirstMedia($collectionName);

        if (!$media) {
            return false;
        }

        return $media->getUrl($conversionName);
    }

    /**
     * Update a media collection by deleting and inserting again with new values.
     *
     * @param array  $newMediaArray
     * @param string $collectionName
     *
     * @throws \Spatie\MediaLibrary\Exceptions\MediaIsNotPartOfCollection
     */
    public function updateMedia(array $newMediaArray, $collectionName = 'default')
    {
        $this->removeMediaItemsNotPresentInArray($newMediaArray, $collectionName);

        $orderColumn = 0;

        foreach ($newMediaArray as $newMediaItem) {
            $currentMedia = Media::findOrFail($newMediaItem['id']);

            if ($currentMedia->collection_name != $collectionName) {
                throw new MediaIsNotPartOfCollection(sprintf('Media id %s is not part of collection %s', $currentMedia->id, $collectionName));
            }

            if (array_key_exists('name', $newMediaItem)) {
                $currentMedia->name = $newMediaItem['name'];
            }

            $currentMedia->temp = 0;
            $currentMedia->order_column = $orderColumn++;

            $currentMedia->save();
        }
    }

    /**
     * @param array  $newMediaArray
     * @param string $collectionName
     */
    protected function removeMediaItemsNotPresentInArray(array $newMediaArray, $collectionName = 'default')
    {
        $this->getMedia($collectionName, [])
            ->filter(function (Media $currentMediaItem) use ($newMediaArray) {
                return !in_array($currentMediaItem->id, collect($newMediaArray)->lists('id')->toArray());
            })
            ->map(function (Media $media) {
                $media->delete();
            });
    }

    /**
     * Remove all media in the given collection.
     *
     * @param string $collectionName
     */
    public function clearMediaCollection($collectionName = 'default')
    {
        $this->getMedia($collectionName)->map(function (Media $media) {
            app(Filesystem::class)->removeFiles($media);
            $media->delete();
        });
    }

    /**
     * Delete the associated media with the given id.
     * You may also pass a media object.
     *
     * @param int|\Spatie\MediaLibrary\Media $mediaId
     *
     * @throws \Spatie\MediaLibrary\Exceptions\MediaDoesNotBelongToModel
     */
    public function deleteMedia($mediaId)
    {
        if ($mediaId instanceof Media) {
            $mediaId = $mediaId->id;
        }

        $media = $this->media->find($mediaId);

        if (!$media) {
            throw new MediaDoesNotBelongToModel('Media id '.$mediaId.' does not belong to this model');
        }

        $media->delete();
    }

    /**
     * Add a conversion.
     *
     * @param string $name
     *
     * @return \Spatie\MediaLibrary\Conversion\Conversion
     */
    public function addMediaConversion($name)
    {
        $conversion = Conversion::create($name);

        $this->mediaConversions[] = $conversion;

        return $conversion;
    }

    /**
     * Delete the model. The extra logic isn't handled in a model event since the boot function is unreliable
     * for currently unknown reasons.
     * 
     * @return bool
     */
    public function delete()
    {
        if (!parent::delete()) {
            return false;
        }
        
        $this->media()->get()->map(function (Media $media) {
            $media->delete();
        });
    }
}
