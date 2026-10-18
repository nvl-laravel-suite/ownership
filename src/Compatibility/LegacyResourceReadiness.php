<?php

declare(strict_types=1);

namespace Nvl\Ownership\Compatibility;

use Illuminate\Foundation\Application;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use ReflectionClass;

/** Rejects incomplete legacy integration graphs during the incremental replacement. */
final readonly class LegacyResourceReadiness
{
    /** @var array<string, string> */
    private const array PROVIDERS = [
        'Nvl\Activity\Providers\ActivityServiceProvider' => 'activity',
        'Nvl\Auth\Providers\AuthServiceProvider' => 'auth',
        'Nvl\Billing\Providers\BillingServiceProvider' => 'billing',
        'Nvl\Comments\Providers\CommentsServiceProvider' => 'comments',
        'Nvl\Content\Providers\ContentServiceProvider' => 'content',
        'Nvl\Csv\Providers\CsvServiceProvider' => 'csv',
        'Nvl\Forms\Providers\FormsServiceProvider' => 'forms',
        'Nvl\MailNotifications\Providers\MailNotificationsServiceProvider' => 'mail-notifications',
        'Nvl\Media\Providers\MediaServiceProvider' => 'media',
        'Nvl\Metafields\Providers\MetafieldsServiceProvider' => 'metafields',
        'Nvl\Pages\Providers\PagesServiceProvider' => 'pages',
        'Nvl\Payments\Providers\PaymentsServiceProvider' => 'payments',
        'Nvl\Seo\Providers\SeoServiceProvider' => 'seo',
        'Nvl\Settings\Providers\SettingsServiceProvider' => 'settings',
        'Nvl\Tasks\Providers\TasksServiceProvider' => 'tasks',
        'Nvl\Taxonomy\Providers\TaxonomyServiceProvider' => 'taxonomy',
        'Nvl\Templates\Providers\TemplatesServiceProvider' => 'templates',
        'Nvl\Translatable\Providers\TranslatableServiceProvider' => 'translatable',
        'Nvl\Translations\Providers\TranslationsServiceProvider' => 'translations',
    ];

    /** Retain framework metadata without retaining scoped principals or models. */
    public function __construct(private Application $application, private TenantResourceRegistry $legacy, private OwnershipResourceRegistry $resources) {}

    /** Require code-owned integration and complete registered resource coverage. */
    public function assertReady(): void
    {
        $loaded = $this->application->getLoadedProviders();
        $definitions = $this->resources->all();
        foreach (self::PROVIDERS as $provider => $package) {
            if (($loaded[$provider] ?? false) !== true) {
                continue;
            }
            if (! (new ReflectionClass($provider))->implementsInterface(OwnershipIntegratedProvider::class)) {
                throw new OwnershipConfigurationInvalid('A loaded package has not implemented ownership integration: '.$package);
            }
            foreach ($this->legacy->all() as $resource) {
                if (str_starts_with($resource->key, $package.'.') && ! isset($definitions[$resource->key])) {
                    throw new OwnershipConfigurationInvalid('A package ownership resource graph is incomplete: '.$package);
                }
            }
        }
    }
}
