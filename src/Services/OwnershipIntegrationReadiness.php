<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Foundation\Application;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use ReflectionClass;

/**
 * Requires native ownership integration from each loaded domain provider.
 *
 * @internal
 */
final readonly class OwnershipIntegrationReadiness
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
    public function __construct(private Application $application, private OwnershipResourceRegistry $resources) {}

    /** Require code-owned integration and complete registered resource coverage. */
    public function assertReady(): void
    {
        $loaded = $this->application->getLoadedProviders();
        $this->resources->freeze();
        foreach (self::PROVIDERS as $provider => $package) {
            if (($loaded[$provider] ?? false) !== true) {
                continue;
            }
            if (! (new ReflectionClass($provider))->implementsInterface(OwnershipIntegratedProvider::class)) {
                throw new OwnershipConfigurationInvalid('A loaded package has not implemented ownership integration: '.$package);
            }

        }
    }
}
