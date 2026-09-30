<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Carbon\CarbonImmutable;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Models\Casts\UtcDateTime;

/**
 * The Restic repository this application/environment is bound to.
 *
 * Established once (explicit initialization, the first proven repository,
 * or a remote manifest) and never changed by the package afterwards.
 *
 * @property int $id
 * @property string $app_id
 * @property string $environment
 * @property string $repository_id
 * @property string $location
 * @property string $source
 * @property CarbonImmutable $established_at
 */
final class RepositoryIdentityRecord extends PackageModel
{
    public const string SOURCE_INITIALIZATION = 'initialization';

    public const string SOURCE_FIRST_PROVEN = 'first_proven';

    public const string SOURCE_REMOTE_MANIFEST = 'remote_manifest';

    protected $table = 'quraba_backup_repository_identities';

    /** @var list<string> */
    protected $fillable = [];

    public static function establish(string $appId, string $environment, string $repositoryId, string $location, string $source): self
    {
        $record = new self;
        $record->setAttribute('app_id', Identifiers::assertUuid($appId, 'The application ID'));
        $record->setAttribute('environment', $environment);
        $record->setAttribute('repository_id', Identifiers::assertRepositoryId($repositoryId));
        $record->setAttribute('location', mb_substr($location, 0, 1024));
        $record->setAttribute('source', $source);
        $record->setAttribute('established_at', CarbonImmutable::now('UTC'));
        $record->save();

        return $record;
    }

    public function setAttribute($key, $value)
    {
        if ($this->exists && in_array($key, ['app_id', 'environment', 'repository_id'], true) && $value !== $this->getRawOriginal($key)) {
            throw new IllegalStateTransition('An established repository identity is immutable.');
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'established_at' => UtcDateTime::class,
        ];
    }
}
