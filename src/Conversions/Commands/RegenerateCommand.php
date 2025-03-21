<?php

namespace Spatie\MediaLibrary\Conversions\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\MediaRepository;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RegenerateCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'media-library:regenerate {modelType?} {--ids=*}
    {--only=* : Regenerate specific conversions}
    {--starting-from-id= : Regenerate media with an id equal to or higher than the provided value}
    {--X|exclude-starting-id : Exclude the provided id when regenerating from a specific id}
    {--only-missing : Regenerate only missing conversions}
    {--with-responsive-images : Regenerate responsive images}
    {--force : Force the operation to run when in production}
    {--queue-all : Queue all conversions, even non-queued ones}';

    protected $description = 'Regenerate the derived images of media';

    protected MediaRepository $mediaRepository;

    protected FileManipulator $fileManipulator;

    protected array $errorMessages = [];

    public function handle(MediaRepository $mediaRepository, FileManipulator $fileManipulator): void
    {
        $this->mediaRepository = $mediaRepository;

        $this->fileManipulator = $fileManipulator;

        if (! $this->confirmToProceed()) {
            return;
        }

        $mediaFiles = $this->getMediaToBeRegenerated();

        $progressBar = $this->output->createProgressBar($mediaFiles->count());

        if (config('media-library.queue_connection_name') === 'sync') {
            set_time_limit(0);
        }

        $mediaFiles->each(function (Media $media) use ($progressBar) {
            try {
                $this->fileManipulator->createDerivedFiles(
                    $media,
                    Arr::wrap($this->option('only')),
                    $this->option('only-missing'),
                    $this->option('with-responsive-images'),
                    $this->option('queue-all'),
                );
            } catch (Exception $exception) {
                $this->errorMessages[$media->getKey()] = $exception->getMessage();
            }

            $progressBar->advance();
        });

        $progressBar->finish();

        if (count($this->errorMessages)) {
            $this->warn('All done, but with some error messages:');

            foreach ($this->errorMessages as $mediaId => $message) {
                $this->warn("Media id {$mediaId}: `{$message}`");
            }
        }

        $this->newLine(2);

        $this->info('All done!');
    }

    public function getMediaToBeRegenerated(): LazyCollection
    {
        // Get this arg first as it can also be passed to the greater-than-id branch
        $modelType = $this->argument('modelType');

        $startingFromId = (int) $this->option('starting-from-id');
        if ($startingFromId !== 0) {
            $excludeStartingId = (bool) $this->option('exclude-starting-id') ?: false;

            return $this->mediaRepository->getByIdGreaterThan($startingFromId, $excludeStartingId, is_string($modelType) ? $modelType : '');
        }

        if (is_string($modelType)) {
            return $this->mediaRepository->getByModelType($modelType);
        }

        $mediaIds = $this->getMediaIds();
        if (count($mediaIds) > 0) {
            return $this->mediaRepository->getByIds($mediaIds);
        }

        return $this->mediaRepository->all();
    }

    protected function getMediaIds(): array
    {
        $mediaIds = $this->option('ids');

        if (! is_array($mediaIds)) {
            $mediaIds = explode(',', (string) $mediaIds);
        }

        if (count($mediaIds) === 1 && Str::contains((string) $mediaIds[0], ',')) {
            $mediaIds = explode(',', (string) $mediaIds[0]);
        }

        return $mediaIds;
    }
}
