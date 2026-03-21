<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Integration;

use Bnomei\ScipLaravel\Tests\Support\AcceptanceTestCase;
use Scip\PositionEncoding;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;

final class LaravelEnrichersAcceptanceTest extends AcceptanceTestCase
{
    public function test_models_routes_views_inertia_and_broadcast_are_covered(): void
    {
        $result = $this->executeCliIndex('integration-heavy.scip', mode: 'full');
        $index = $this->loadIndex('integration-heavy.scip');

        self::assertSame(0, $result->exitCode);
        self::assertGreaterThan(0, $index->documentCount());
        self::assertSame('scip-laravel', $index->metadataToolName());
        self::assertNotSame('', $index->metadataToolVersion() ?? '');
        self::assertSame(
            PositionEncoding::UTF8CodeUnitOffsetFromLineStart,
            $index->documentPositionEncoding('routes/acceptance.php'),
        );
        self::assertSame(PositionEncoding::UTF8CodeUnitOffsetFromLineStart, $index->symbolSignaturePositionEncoding(
            'app/Http/Controllers/AcceptanceValidatedRouteController.php',
            'AcceptanceValidatedRouteController#store().',
        ));

        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceModelProbe.php',
            'AcceptanceUser#displayName().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceModelProbe.php',
            'AcceptanceUser#displayName().',
            SymbolRole::WriteAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-model.blade.php',
            'AcceptanceUser#profiles().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-route-bound.blade.php',
            'AcceptanceUser#displayName().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-route-bound.blade.php',
            'AcceptanceUser#profiles().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-computed-model.blade.php',
            'AcceptanceUser#declaredSummary().',
            SymbolRole::ReadAccess,
        ));
        self::assertFalse($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-unsupported-route-bound.blade.php',
            'AcceptanceUser#displayName().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/team/member.blade.php',
            'App%5CModels%5CMember%23role.',
            SymbolRole::WriteAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/team/member.blade.php',
            'App/Models/Member#roleColor().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/team/member.blade.php',
            'App/Models/Member#roleLabel().',
            SymbolRole::ReadAccess,
        ));
        $declaredNicknameSymbol = $index->findSymbolByDisplayName('app/Models/AcceptanceUser.php', '$nickname');
        self::assertNotNull($declaredNicknameSymbol);
        self::assertSame(Kind::Property, $index->findSymbolKindByDisplayName(
            'app/Models/AcceptanceUser.php',
            '$nickname',
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceModelProbe.php',
            'AcceptanceUser#$nickname.',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceModelProbe.php',
            'AcceptanceUser#$nickname.',
            SymbolRole::WriteAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Models/AcceptanceUser.php',
            'AcceptanceUser#$nickname.',
            SymbolRole::ReadAccess,
        ));

        $declaredSummarySymbol = $index->findSymbolByDisplayName('app/Models/AcceptanceUser.php', 'declaredSummary()');
        self::assertNotNull($declaredSummarySymbol);
        self::assertSame(Kind::Method, $index->findSymbolKindByDisplayName(
            'app/Models/AcceptanceUser.php',
            'declaredSummary()',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceModelProbe.php',
            'AcceptanceUser#declaredSummary().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Models/AcceptanceUser.php',
            'AcceptanceUser#declaredSummary().',
            SymbolRole::ReadAccess,
        ));

        $declaredSlugSymbol = $index->findSymbolByDisplayName('app/Models/AcceptanceUser.php', 'declaredSlug()');
        self::assertNotNull($declaredSlugSymbol);
        self::assertSame(Kind::Method, $index->findSymbolKindByDisplayName(
            'app/Models/AcceptanceUser.php',
            'declaredSlug()',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceModelProbe.php',
            'AcceptanceUser#declaredSlug().',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Models/AcceptanceUser.php',
            'AcceptanceUser#declaredSlug().',
            SymbolRole::ReadAccess,
        ));

        $defaultLabelSymbol = $index->findSymbolByDisplayName('app/Models/AcceptanceUser.php', 'DEFAULT_LABEL');
        self::assertNotNull($defaultLabelSymbol);
        self::assertSame(Kind::Constant, $index->findSymbolKindByDisplayName(
            'app/Models/AcceptanceUser.php',
            'DEFAULT_LABEL',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceModelProbe.php',
            'AcceptanceUser#DEFAULT_LABEL.',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Models/AcceptanceUser.php',
            'AcceptanceUser#DEFAULT_LABEL.',
            SymbolRole::ReadAccess,
        ));
        self::assertFalse($index->hasAnySymbolDisplayName('volt-livewire::feed'));
        self::assertFalse($index->hasAnySymbolDisplayName('volt-livewire::acceptance-validation'));

        $dashboardRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'dashboard',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertSame(Kind::Key, $index->findSymbolKindByDisplayName('routes/acceptance.php', 'dashboard'));
        self::assertSame(4, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceRouteProbe.php',
            $dashboardRouteSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/dashboard-link.blade.php',
            $dashboardRouteSymbol,
            SymbolRole::ReadAccess,
        ));

        $acceptanceRouteProbeSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.route-probe',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->occurrenceOverrideDocumentationContains(
            'app/Support/AcceptanceRouteProbe.php',
            'routes/`acceptance.route-probe`.',
            SymbolRole::ReadAccess,
            'Laravel route: acceptance.route-probe',
        ));
        $acceptanceInertiaRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.inertia',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceInertiaRouteSymbol,
            'Responses: Inertia Acceptance/Dashboard',
        ));
        $acceptanceDashboardSymbol = $this->assertDefinitionAndReference(
            $index,
            'resources/js/Pages/Acceptance/Dashboard.vue',
            'Acceptance/Dashboard',
            'app/Http/Controllers/AcceptanceInertiaController.php',
        );
        $canCreatePropSymbol = $index->findSymbolByDisplayName(
            'resources/js/Pages/Acceptance/Dashboard.vue',
            'canCreate',
        );
        self::assertNotNull($canCreatePropSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'resources/js/Pages/Acceptance/Dashboard.vue',
            $canCreatePropSymbol,
            'Inertia prop: canCreate',
        ));
        self::assertTrue($index->symbolEnclosingContains(
            'resources/js/Pages/Acceptance/Dashboard.vue',
            $canCreatePropSymbol,
            $acceptanceDashboardSymbol,
        ));
        self::assertTrue($index->hasOccurrence(
            'resources/js/Pages/Acceptance/Dashboard.vue',
            $canCreatePropSymbol,
            SymbolRole::Definition,
        ));
        $headlineSharedSymbol = $index->findSymbolByDisplayName(
            'app/Http/Middleware/AcceptanceHandleInertiaRequests.php',
            'headline',
        );
        self::assertNotNull($headlineSharedSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Middleware/AcceptanceHandleInertiaRequests.php',
            $headlineSharedSymbol,
            'Inertia shared data: headline',
        ));
        self::assertTrue($index->hasOccurrence(
            'resources/js/Pages/Acceptance/Dashboard.vue',
            $headlineSharedSymbol,
            SymbolRole::ReadAccess,
        ));
        $acceptanceValidatedRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.validated',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteSymbol,
            'Laravel route: POST /acceptance/validated/{status}',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteSymbol,
            'Parameters: status: App\\Enums\\AcceptanceStatus',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteSymbol,
            'Validator rules: status => in:draft,published|required; title => max:120|required|string',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteSymbol,
            'Responses: JSON contract: status: string, title: mixed',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            'route-parameter/`acceptance.validated:status`.',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            'App/Enums/AcceptanceStatus#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Http/Controllers/AcceptanceValidatedRouteController.php',
            'route-parameter/`acceptance.validated:status`.',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            'route-parameter/`acceptance.validated:status`.',
            'Route placeholder: {status}',
        ));
        $acceptanceValidatedRouteResponseSymbol = $index->findSymbolEndingWith(
            'routes/acceptance.php',
            'route-response/`acceptance.validated`.',
        );
        self::assertNotNull($acceptanceValidatedRouteResponseSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteResponseSymbol,
            'Route response contract',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteResponseSymbol,
            'Responses: JSON contract: status: string, title: mixed',
        ));
        self::assertTrue($index->hasOccurrenceWithEnclosingRange(
            'routes/acceptance.php',
            $acceptanceValidatedRouteResponseSymbol,
            SymbolRole::Definition,
        ));
        $acceptanceValidatedRouteValidatorSymbol = $index->findSymbolEndingWith(
            'routes/acceptance.php',
            'route-validator/`acceptance.validated`.',
        );
        self::assertNotNull($acceptanceValidatedRouteValidatorSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteValidatorSymbol,
            'Route validator contract',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceValidatedRouteValidatorSymbol,
            'Validator rules: status => in:draft,published|required; title => max:120|required|string',
        ));
        self::assertTrue($index->hasOccurrenceWithEnclosingRange(
            'routes/acceptance.php',
            $acceptanceValidatedRouteValidatorSymbol,
            SymbolRole::Definition,
        ));
        $acceptanceOptionalRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.optional',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceOptionalRouteSymbol,
            'default: fallback',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            'route-parameter/`acceptance.optional:slug`.',
            'Route placeholder: {slug?}',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            'route-parameter/`acceptance.optional:slug`.',
            'Route default: fallback',
        ));
        $acceptanceStaticViewRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.static-view',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceStaticViewRouteSymbol,
            '/acceptance/static-view',
        ));
        $acceptanceRedirectRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.redirect',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceRedirectRouteSymbol,
            'Redirect target: /acceptance/view',
        ));
        $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.articles.index',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.profile.show',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'AcceptanceArticleController#index().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'AcceptanceArticleResource#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'AcceptanceArticleResource#toArray().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'AcceptanceSummary#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'AcceptanceSummary#jsonSerialize().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'AcceptanceProfileController#show().',
            SymbolRole::ReadAccess,
        ));
        $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.volt',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $acceptanceNavigationRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.navigation',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $acceptanceAuthorizationRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.authorization',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $acceptanceLayoutChildRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.layout-child',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $acceptancePlaceholderRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.placeholder',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $acceptanceRealtimeRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.livewire.realtime',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $acceptanceExplicitRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.livewire.explicit',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $acceptanceEnumBoundRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.enum-bound',
            'app/Support/AcceptanceRouteProbe.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/acceptance/route-show.blade.php',
            'acceptance.route-show',
            'routes/acceptance.php',
        );
        self::assertTrue($index->hasOccurrence(
            'resources/views/acceptance/route-show.blade.php',
            $acceptanceRouteProbeSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceRouteProbeSymbol,
            'Livewire navigate modifiers: hover',
        ));
        self::assertSame(Kind::Key, $index->findSymbolKindByDisplayName(
            'routes/acceptance.php',
            'acceptance.route-probe',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceStaticViewRouteSymbol,
            'Livewire current state: exact',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceExplicitRouteSymbol,
            'Laravel explicit binding: account => App\\Models\\AcceptanceUser',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceExplicitRouteSymbol,
            'Laravel scoped bindings: enabled',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceExplicitRouteSymbol,
            'Laravel missing handler: acceptance.route-probe',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceExplicitRouteSymbol,
            'Laravel authorization target: App\\Models\\AcceptanceUser via account',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceEnumBoundRouteSymbol,
            'Laravel enum binding: statusBound => App\\Enums\\AcceptanceStatus',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/acceptance/navigation.blade.php',
            $acceptanceRouteProbeSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/acceptance/navigation.blade.php',
            $acceptanceStaticViewRouteSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceRouteProbe.php',
            $acceptanceNavigationRouteSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceRouteProbe.php',
            $acceptancePlaceholderRouteSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceRouteProbe.php',
            $acceptanceAuthorizationRouteSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/navigation.blade.php',
            $acceptanceNavigationRouteSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/navigation.blade.php',
            $acceptancePlaceholderRouteSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'AcceptanceUser#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrence(
            'routes/acceptance.php',
            $acceptanceRouteProbeSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(0, $index->countDocumentsWithPrefix('storage/framework/views/'));

        $this->assertDefinitionAndReference(
            $index,
            'resources/views/acceptance/prefixed-probe.blade.php',
            'acceptance.prefixed-probe',
            'app/Http/Controllers/AcceptanceViewController.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/livewire/posts.blade.php',
            'livewire.posts',
            'resources/views/acceptance/prefixed-probe.blade.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/livewire/posts.blade.php',
            'livewire.posts',
            'resources/views/acceptance/livewire-directive.blade.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/components/acceptance/⚡banner-panel.blade.php',
            'components.acceptance.⚡banner-panel',
            'resources/views/acceptance/livewire-alias.blade.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/pages/settings/profile.blade.php',
            'pages.settings.profile',
            'app/Support/ScipAcceptanceLivewireEntrypointProbe.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/components/acceptance/⚡banner-panel.blade.php',
            'components.acceptance.⚡banner-panel',
            'app/Support/ScipAcceptanceLivewireEntrypointProbe.php',
        );
        self::assertSame(3, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceDirective#save().',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(6, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceDirective#$title.',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(4, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceDirective#$title.',
            SymbolRole::WriteAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceChildInput#$value.',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceChildInput#$value.',
            SymbolRole::WriteAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceReactiveChild#$title.',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceReactiveChild#$open.',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'App/Livewire/AcceptanceDirective#$open.',
            SymbolRole::ReadAccess,
        ));
        self::assertFalse($index->documentHasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-directive.blade.php',
            'local livewire-',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceChildInput.php',
            'AcceptanceChildInput#$value.',
            'Livewire modelable property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceReactiveChild.php',
            'AcceptanceReactiveChild#$title.',
            'Livewire reactive property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceReactiveChild.php',
            'AcceptanceReactiveChild#$open.',
            'Livewire reactive property',
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/team/profile.blade.php',
            'local livewire-method-save',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/team/profile.blade.php',
            'local livewire-property-name',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/team/profile.blade.php',
            'local livewire-property-name',
            SymbolRole::WriteAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/team/member.blade.php',
            'local livewire-method-edit',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/team/member.blade.php',
            'local livewire-method-remove',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/team/member.blade.php',
            'local livewire-method-update',
            SymbolRole::ReadAccess,
        ));
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/flux/icon/github.blade.php',
            'flux.icon.github',
            'resources/views/acceptance/prefixed-probe.blade.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/components/acceptance/card.blade.php',
            'components.acceptance.card',
            'resources/views/acceptance/blade-locals.blade.php',
        );
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/acceptance/component-tags.blade.php',
            'App/View/Components/Acceptance/Banner#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/components/acceptance/banner.blade.php',
            'App/View/Components/Acceptance/Banner#$type.',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/components/acceptance/banner.blade.php',
            'App/View/Components/Acceptance/Banner#$message.',
            SymbolRole::ReadAccess,
        ));
        $attributeBagSymbol = $index->findExternalSymbolByDisplayName('ComponentAttributeBag');
        self::assertNotNull($attributeBagSymbol);
        self::assertTrue($index->hasOccurrence(
            'resources/views/components/acceptance/banner.blade.php',
            $attributeBagSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->externalSymbolDocumentationContains(
            'ComponentAttributeBag',
            'Laravel Blade attribute bag',
        ));
        $bladePropSymbol = $index->findSymbolByDisplayName(
            'resources/views/components/acceptance/card.blade.php',
            'title',
        );
        self::assertNotNull($bladePropSymbol);
        self::assertTrue($index->hasOccurrenceWithEnclosingRange(
            'resources/views/components/acceptance/card.blade.php',
            $bladePropSymbol,
            SymbolRole::Definition,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'resources/views/components/acceptance/card.blade.php',
            'local blade-prop-title',
            SymbolRole::ReadAccess,
        ));
        $bladeAwareSymbol = $index->findSymbolByDisplayName(
            'resources/views/components/acceptance/card.blade.php',
            'accent',
        );
        self::assertNotNull($bladeAwareSymbol);
        self::assertTrue($index->hasOccurrenceWithEnclosingRange(
            'resources/views/components/acceptance/card.blade.php',
            $bladeAwareSymbol,
            SymbolRole::Definition,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/components/acceptance/card.blade.php',
            'local blade-aware-accent',
            SymbolRole::ReadAccess,
        ));
        $bladeSlotFooterSymbol = $index->findSymbolByDisplayName(
            'resources/views/acceptance/blade-locals.blade.php',
            'footer',
        );
        self::assertNotNull($bladeSlotFooterSymbol);
        self::assertTrue($index->hasOccurrenceWithEnclosingRange(
            'resources/views/acceptance/blade-locals.blade.php',
            $bladeSlotFooterSymbol,
            SymbolRole::Definition,
        ));
        $bladeSlotActionsSymbol = $index->findSymbolByDisplayName(
            'resources/views/acceptance/blade-locals.blade.php',
            'actions',
        );
        self::assertNotNull($bladeSlotActionsSymbol);
        self::assertTrue($index->hasOccurrenceWithEnclosingRange(
            'resources/views/acceptance/blade-locals.blade.php',
            $bladeSlotActionsSymbol,
            SymbolRole::Definition,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/blade-locals.blade.php',
            'local blade-slot-',
            SymbolRole::Definition,
        ));
        self::assertFalse($index->documentHasOccurrenceSymbolContaining(
            'resources/views/acceptance/blade-locals.blade.php',
            'local blade-slot-ignored',
        ));
        self::assertFalse($index->hasAnySymbolDisplayName('flux.heading'));
        $fluxButtonSymbol = $index->findExternalSymbolByDisplayName('flux:button');
        self::assertNotNull($fluxButtonSymbol);
        self::assertTrue($index->hasOccurrence(
            'resources/views/acceptance/prefixed-probe.blade.php',
            $fluxButtonSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->externalSymbolDocumentationContains('flux:button', 'livewire/flux'));
        self::assertTrue($index->externalSymbolDocumentationContains('flux:button', 'Flux props:'));
        self::assertTrue($index->externalSymbolDocumentationContains('flux:button', 'data-flux'));
        self::assertNull($index->findExternalSymbolByDisplayName('flux:icon.github'));
        $acceptanceValidationTitleSymbol = $index->findSymbolEndingWith(
            'app/Livewire/AcceptanceValidation.php',
            'AcceptanceValidation#$title.',
        );
        self::assertNotNull($acceptanceValidationTitleSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            $acceptanceValidationTitleSymbol,
            'Route validator rules: max:120|required|string',
        ));
        $acceptanceEnumSymbol = $index->findSymbolEndingWith('app/Enums/AcceptanceStatus.php', 'AcceptanceStatus#');
        self::assertNotNull($acceptanceEnumSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Enums/AcceptanceStatus.php',
            $acceptanceEnumSymbol,
            'Laravel enum cases: Draft="draft", Published="published"',
        ));
        $acceptanceEnumDraftSymbol = $index->findSymbolEndingWith(
            'app/Enums/AcceptanceStatus.php',
            'AcceptanceStatus#Draft.',
        );
        self::assertNotNull($acceptanceEnumDraftSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Enums/AcceptanceStatus.php',
            $acceptanceEnumDraftSymbol,
            'Laravel enum case value: "draft"',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Controllers/AcceptanceValidatedRouteController.php',
            'AcceptanceValidatedRouteController#store().',
            'Laravel route: acceptance.validated [POST] /acceptance/validated/{status}',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Controllers/AcceptanceValidatedRouteController.php',
            'AcceptanceValidatedRouteController#store().',
            'Laravel validation rules: status => in:draft,published|required; title => max:120|required|string',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceAttributes.php',
            'App/Livewire/AcceptanceAttributes#',
            'Livewire title: Acceptance Attributes',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceAttributes.php',
            'App/Livewire/AcceptanceAttributes#$postId.',
            'Livewire locked property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceAttributes.php',
            'App/Livewire/AcceptanceAttributes#$filter.',
            'Livewire session property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceAttributes.php',
            'App/Livewire/AcceptanceAttributes#$query.',
            'Livewire URL property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceAttributes.php',
            'App/Livewire/AcceptanceAttributes#$title.',
            'Livewire validation: required|min:3',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceAttributes.php',
            'App/Livewire/AcceptanceAttributes#total().',
            'Livewire computed property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceAttributes.php',
            'App/Livewire/AcceptanceAttributes#refreshAfterSave().',
            'Livewire event listener: saved',
        ));
        $savedEventSymbol = $index->findExternalSymbolByDisplayName('saved');
        self::assertNotNull($savedEventSymbol);
        self::assertTrue($index->hasOccurrence(
            'app/Livewire/AcceptanceEventSource.php',
            $savedEventSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrence(
            'app/Livewire/AcceptanceEventTarget.php',
            $savedEventSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(6, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-browser-events.blade.php',
            $savedEventSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-browser-events.blade.php',
            'App/Livewire/AcceptanceBrowserEvents#save().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->externalSymbolDocumentationContains('saved', 'Livewire event: saved'));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceEventSource.php',
            'App/Livewire/AcceptanceEventSource#emitSaved().',
            'Livewire event dispatch: saved',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceEventTarget.php',
            'App/Livewire/AcceptanceEventTarget#refreshAfterSave().',
            'Livewire event listener: saved',
        ));
        $loadingSymbol = $index->findExternalSymbolByDisplayName('loading');
        self::assertNotNull($loadingSymbol);
        self::assertTrue($index->hasOccurrence(
            'resources/views/livewire/acceptance-directive.blade.php',
            $loadingSymbol,
            SymbolRole::ReadAccess,
        ));
        foreach (['ignore', 'replace', 'offline'] as $uiDirective) {
            $symbol = $index->findExternalSymbolByDisplayName($uiDirective);
            self::assertNotNull($symbol);
            self::assertTrue($index->hasOccurrence(
                'resources/views/livewire/acceptance-directive.blade.php',
                $symbol,
                SymbolRole::ReadAccess,
            ));
        }
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-realtime.blade.php',
            'App/Livewire/AcceptanceRealtime#refresh().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-realtime.blade.php',
            'livewire-stream',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-realtime.blade.php',
            'livewire-ref',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-explicit-route-bound.blade.php',
            'AcceptanceUser#displayName().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceRealtime.php',
            'App/Livewire/AcceptanceRealtime#refresh().',
            'Livewire poll modifiers: 5s',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceRealtime.php',
            'App/Livewire/AcceptanceRealtime#$photo.',
            'Livewire file upload property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceRealtime.php',
            'App/Livewire/AcceptanceRealtime#$photo.',
            'Livewire file uploads enabled via WithFileUploads',
        ));
        $manageAcceptanceAbilitySymbol = $index->findExternalSymbolByDisplayName('manage-acceptance');
        self::assertNotNull($manageAcceptanceAbilitySymbol);
        self::assertTrue($index->hasOccurrence(
            'routes/acceptance.php',
            $manageAcceptanceAbilitySymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrence(
            'app/Http/Controllers/AcceptanceAuthorizedController.php',
            $manageAcceptanceAbilitySymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrence(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            $manageAcceptanceAbilitySymbol,
            SymbolRole::ReadAccess,
        ));
        $authMiddlewareSymbol = $index->findExternalSymbolByDisplayName('auth');
        self::assertNotNull($authMiddlewareSymbol);
        self::assertTrue($index->hasOccurrence('routes/acceptance.php', $authMiddlewareSymbol, SymbolRole::ReadAccess));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/acceptance.php',
            'App/Http/Middleware/EnsureAcceptanceToken#',
            SymbolRole::ReadAccess,
        ));
        $acceptanceAuthorizedRouteSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.authorized',
            'app/Support/AcceptanceRouteProbe.php',
        );
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceAuthorizedRouteSymbol,
            'Laravel middleware: auth',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'routes/acceptance.php',
            $acceptanceAuthorizedRouteSymbol,
            'Laravel authorization ability: manage-acceptance',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Controllers/AcceptanceAuthorizedController.php',
            'AcceptanceAuthorizedController#store().',
            'Laravel authorization ability: manage-acceptance',
        ));
        $acceptanceRequestSymbol = $index->findSymbolEndingWith(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            'AcceptanceValidatedRequest#',
        );
        self::assertNotNull($acceptanceRequestSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            $acceptanceRequestSymbol,
            'Laravel Form Request rules: status => in:draft,published|required; title => max:120|required|string',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            $acceptanceRequestSymbol,
            'Validation messages: title.required => The title is required.',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            $acceptanceRequestSymbol,
            'Validation attributes: title => headline',
        ));
        $acceptanceRequestRulesSymbol = $index->findSymbolEndingWith(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            'AcceptanceValidatedRequest#rules().',
        );
        self::assertNotNull($acceptanceRequestRulesSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            $acceptanceRequestRulesSymbol,
            'Laravel Form Request rules: status => in:draft,published|required; title => max:120|required|string',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            'AcceptanceValidatedRequest#authorize().',
            'Laravel authorization ability: manage-acceptance',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Providers/AcceptanceGraphServiceProvider.php',
            'AcceptanceGreeter',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Providers/AcceptanceGraphServiceProvider.php',
            'AcceptanceGreeterService',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Providers/AcceptanceGraphServiceProvider.php',
            'AcceptanceConsumer',
            SymbolRole::ReadAccess,
        ));
        $repositoryContractSymbol = $index->findExternalSymbolByDisplayName('Repository');
        self::assertNotNull($repositoryContractSymbol);
        self::assertTrue($index->hasOccurrence(
            'app/Providers/AcceptanceGraphServiceProvider.php',
            $repositoryContractSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->externalSymbolDocumentationContains(
            'Repository',
            'Illuminate\\Contracts\\Cache\\Repository',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Contracts/AcceptanceGreeter.php',
            'AcceptanceGreeter#',
            'Laravel container binding (singleton): App\\Contracts\\AcceptanceGreeter -> App\\Services\\AcceptanceGreeterService',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Contracts/AcceptanceGreeter.php',
            'AcceptanceGreeter#',
            'Laravel contextual binding: App\\Services\\AcceptanceConsumer needs App\\Contracts\\AcceptanceGreeter -> App\\Services\\AcceptanceGreeterService',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Contracts/AcceptancePusher.php',
            'AcceptancePusher#',
            'Laravel container binding (bind): App\\Contracts\\AcceptancePusher -> App\\Services\\AcceptancePusherService',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Contracts/AcceptancePusher.php',
            'AcceptancePusher#',
            'environments: local, testing',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Services/AcceptancePusherService.php',
            'AcceptancePusherService#',
            'Laravel container lifetime: singleton',
        ));
        self::assertTrue($index->symbolHasImplementationRelationship(
            'app/Services/AcceptancePusherService.php',
            'AcceptancePusherService#',
            'AcceptancePusher#',
        ));
        self::assertTrue($index->symbolHasImplementationRelationship(
            'tests/Fakes/AcceptancePusherFake.php',
            'AcceptancePusherFake#',
            'AcceptancePusher#',
        ));
        self::assertTrue($index->symbolHasImplementationRelationship(
            'app/Support/Drivers/AcceptanceSupportNullRecorder.php',
            'AcceptanceSupportNullRecorder#',
            'AcceptanceSupportRecorder#',
        ));
        self::assertTrue($index->symbolHasImplementationRelationship(
            'tests/Fakes/AcceptanceSupportRecorderFake.php',
            'AcceptanceSupportRecorderFake#',
            'AcceptanceSupportRecorder#',
        ));
        self::assertTrue($index->symbolHasTypeDefinitionRelationship(
            'app/Support/Contracts/AcceptanceSupportExtendedRecorder.php',
            'AcceptanceSupportExtendedRecorder#',
            'AcceptanceSupportRecorder#',
        ));
        self::assertFalse($index->symbolHasImplementationRelationship(
            'app/Support/Contracts/AcceptanceSupportExtendedRecorder.php',
            'AcceptanceSupportExtendedRecorder#',
            'AcceptanceSupportRecorder#',
        ));
        self::assertTrue($index->symbolHasReferenceRelationship(
            'app/Services/AcceptancePusherService.php',
            'AcceptancePusherService#',
            'AcceptancePusher#',
        ));
        self::assertTrue($index->symbolHasReferenceRelationship(
            'tests/Fakes/AcceptancePusherFake.php',
            'AcceptancePusherFake#',
            'AcceptancePusher#',
        ));
        self::assertTrue($index->symbolHasReferenceRelationship(
            'app/Support/Drivers/AcceptanceSupportNullRecorder.php',
            'AcceptanceSupportNullRecorder#',
            'AcceptanceSupportRecorder#',
        ));
        self::assertTrue($index->symbolHasReferenceRelationship(
            'tests/Fakes/AcceptanceSupportRecorderFake.php',
            'AcceptanceSupportRecorderFake#',
            'AcceptanceSupportRecorder#',
        ));
        self::assertTrue($index->symbolHasReferenceRelationship(
            'app/Support/Contracts/AcceptanceSupportExtendedRecorder.php',
            'AcceptanceSupportExtendedRecorder#',
            'AcceptanceSupportRecorder#',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Services/AcceptanceScopedService.php',
            'AcceptanceScopedService#',
            'Laravel container lifetime: scoped',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Services/AcceptanceConsumer.php',
            'AcceptanceConsumer#',
            'Laravel contextual attribute: config => app.name',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Services/AcceptanceConsumer.php',
            'AcceptanceConsumer#',
            'Laravel contextual attribute: cache => redis',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Services/AcceptanceConsumer.php',
            'config/`app.name`.',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Services/AcceptanceConsumer.php',
            'container-context/`cache:redis`.',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceAsyncProbe.php',
            'AcceptanceSendDigestJob#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceAsyncProbe.php',
            'AcceptanceAsyncEvent#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Support/AcceptanceAsyncProbe.php',
            'AcceptanceDigestNotification#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Providers/AcceptanceGraphServiceProvider.php',
            'AcceptanceAsyncEvent#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'app/Providers/AcceptanceGraphServiceProvider.php',
            'AcceptanceAsyncListener#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Jobs/AcceptanceSendDigestJob.php',
            'AcceptanceSendDigestJob#',
            'Laravel queued job',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Listeners/AcceptanceAsyncListener.php',
            'AcceptanceAsyncListener#',
            'Laravel queued listener',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Notifications/AcceptanceDigestNotification.php',
            'AcceptanceDigestNotification#',
            'Laravel queued notification',
        ));
        $withoutOverlappingSymbol = $index->findExternalSymbolByDisplayName('WithoutOverlapping');
        self::assertNotNull($withoutOverlappingSymbol);
        self::assertTrue($index->hasOccurrence(
            'app/Jobs/AcceptanceSendDigestJob.php',
            $withoutOverlappingSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrence(
            'app/Listeners/AcceptanceAsyncListener.php',
            $withoutOverlappingSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->externalSymbolDocumentationContains(
            'WithoutOverlapping',
            'Illuminate\\Queue\\Middleware\\WithoutOverlapping',
        ));
        $this->assertDefinitionAndReference(
            $index,
            'app/Console/Commands/AcceptanceReportCommand.php',
            'acceptance:report',
            'routes/console.php',
        );
        $acceptanceClosureCommandSymbol = $index->findSymbolByDisplayName('routes/console.php', 'acceptance:closure');
        self::assertNotNull($acceptanceClosureCommandSymbol);
        self::assertTrue($index->hasOccurrence(
            'routes/console.php',
            $acceptanceClosureCommandSymbol,
            SymbolRole::Definition,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/console.php',
            'AcceptanceSendDigestJob#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/console.php',
            'AcceptanceScheduleProbe#run().',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Console/Commands/AcceptanceReportCommand.php',
            'AcceptanceReportCommand#',
            'Laravel schedule: hourly',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Console/Commands/AcceptanceReportCommand.php',
            'AcceptanceReportCommand#',
            'Laravel schedule: withoutOverlapping',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Console/Commands/AcceptanceReportCommand.php',
            'AcceptanceReportCommand#',
            'Laravel schedule: runInBackground',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Jobs/AcceptanceSendDigestJob.php',
            'AcceptanceSendDigestJob#',
            'Laravel schedule: everyFiveMinutes',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Jobs/AcceptanceSendDigestJob.php',
            'AcceptanceSendDigestJob#',
            'Laravel schedule: onOneServer',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Support/AcceptanceScheduleProbe.php',
            'AcceptanceScheduleProbe#run().',
            'Laravel schedule: dailyAt(13:00)',
        ));
        self::assertTrue($index->hasOccurrence(
            'resources/views/acceptance/authorization.blade.php',
            $manageAcceptanceAbilitySymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(3, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/authorization.blade.php',
            'AcceptanceUser#',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(3, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/authorization.blade.php',
            'AcceptanceUserPolicy#',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Policies/AcceptanceUserPolicy.php',
            'AcceptanceUserPolicy#update().',
            'Laravel authorization ability: update',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            'App/Livewire/AcceptanceValidation#$title.',
            'Validator title is required.',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            'App/Livewire/AcceptanceValidation#$title.',
            'validator headline',
        ));
        self::assertTrue($index->symbolSignatureContains(
            'app/Livewire/AcceptanceDirective.php',
            'AcceptanceDirective#$title.',
            '$title: string',
        ));
        self::assertTrue($index->symbolSignatureContains(
            'app/Livewire/AcceptanceDirective.php',
            'AcceptanceDirective#load().',
            'load(): void',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'resources/views/livewire/infinite-scroll.blade.php',
            'local livewire-property-component',
            'Livewire locked property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'resources/views/livewire/infinite-scroll.blade.php',
            'local livewire-property-page',
            'Livewire URL property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'resources/views/livewire/acceptance-attributes-volt.blade.php',
            'local livewire-property-summary',
            'Livewire computed property',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'resources/views/livewire/acceptance-attributes-volt.blade.php',
            'acceptance-attributes-volt',
            'Livewire title: Acceptance Volt Attributes',
        ));
        $layoutSectionSymbol = $index->findSymbolByDisplayName(
            'resources/views/layouts/acceptance-shell.blade.php',
            'content',
        );
        self::assertNotNull($layoutSectionSymbol);
        self::assertTrue($index->hasOccurrence(
            'resources/views/layouts/acceptance-shell.blade.php',
            $layoutSectionSymbol,
            SymbolRole::Definition,
        ));
        self::assertTrue($index->hasOccurrence(
            'resources/views/acceptance/layout-child.blade.php',
            $layoutSectionSymbol,
            SymbolRole::ReadAccess,
        ));
        $layoutStackSymbol = $index->findSymbolByDisplayName(
            'resources/views/layouts/acceptance-shell.blade.php',
            'scripts',
        );
        self::assertNotNull($layoutStackSymbol);
        self::assertTrue($index->hasOccurrence(
            'resources/views/layouts/acceptance-shell.blade.php',
            $layoutStackSymbol,
            SymbolRole::Definition,
        ));
        self::assertTrue($index->hasOccurrence(
            'resources/views/acceptance/layout-child.blade.php',
            $layoutStackSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolEnclosingContains(
            'resources/views/flux/icon/github.blade.php',
            'local blade-prop-variant',
            'views/`flux.icon.github`.',
        ));
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/components/layouts/app.blade.php',
            'components.layouts.app',
            'app/Livewire/AcceptanceAttributes.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/components/layouts/app.blade.php',
            'components.layouts.app',
            'resources/views/livewire/acceptance-attributes-volt.blade.php',
        );

        $this->assertDefinitionAndReference(
            $index,
            'resources/views/livewire/posts.blade.php',
            'livewire.posts',
            'app/Support/AcceptanceLivewireProbe.php',
        );
        $this->assertDefinitionAndReference(
            $index,
            'resources/views/livewire/acceptance-model.blade.php',
            'livewire.acceptance-model',
            'app/Support/AcceptanceLivewireProbe.php',
        );

        $acceptanceBroadcastChannelSymbol = $this->assertDefinitionAndReference(
            $index,
            'routes/channels.php',
            'acceptance.{userId}',
            'app/Events/AcceptanceBroadcastProbe.php',
        );
        self::assertTrue($index->symbolDocumentationContains(
            'routes/channels.php',
            $acceptanceBroadcastChannelSymbol,
            'Broadcast resolves to: App\\Models\\User',
        ));
        self::assertTrue($index->hasOccurrenceSymbolContaining(
            'routes/channels.php',
            'App/Models/User#',
            SymbolRole::ReadAccess,
        ));
        $acceptanceBroadcastPayloadSymbol = $index->findSymbolByDisplayName(
            'app/Events/AcceptanceBroadcastProbe.php',
            'payload',
        );
        self::assertNotNull($acceptanceBroadcastPayloadSymbol);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Events/AcceptanceBroadcastProbe.php',
            $acceptanceBroadcastPayloadSymbol,
            'Broadcast payload contract',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Events/AcceptanceBroadcastProbe.php',
            $acceptanceBroadcastPayloadSymbol,
            'Broadcast payload: status: string, userId: int',
        ));
        self::assertTrue($index->hasOccurrenceWithEnclosingRange(
            'app/Events/AcceptanceBroadcastProbe.php',
            $acceptanceBroadcastPayloadSymbol,
            SymbolRole::Definition,
        ));
        self::assertSame(Kind::PBClass, $index->externalSymbolKind('PrivateChannel'));
        self::assertTrue($index->externalSymbolDocumentationContains(
            'PrivateChannel',
            'External PHP class: Illuminate\\Broadcasting\\PrivateChannel',
        ));
        self::assertTrue($index->externalSymbolSignatureContains('PrivateChannel', 'class PrivateChannel'));
        self::assertTrue($index->occurrenceDiagnosticContains(
            'resources/views/acceptance/diagnostics.blade.php',
            'blade.dynamic-view-target',
            'Unsupported dynamic Blade view target.',
        ));
        self::assertTrue($index->occurrenceDiagnosticContains(
            'resources/views/acceptance/diagnostics.blade.php',
            'blade.dynamic-component',
            'Unsupported dynamic Blade component target.',
        ));
    }

    public function test_config_translations_and_env_are_covered(): void
    {
        $result = $this->executeCliIndex('integration-strings.scip', mode: 'full');
        $index = $this->loadIndex('integration-strings.scip');

        self::assertSame(0, $result->exitCode);

        $configSymbol = $this->assertDefinitionAndReference(
            $index,
            'config/scip-acceptance.php',
            'scip-acceptance.ui.label',
            'app/Support/AcceptanceConfigProbe.php',
        );
        self::assertSame(Kind::Key, $index->findSymbolKindByDisplayName(
            'config/scip-acceptance.php',
            'scip-acceptance.ui.label',
        ));
        self::assertSame(3, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceConfigProbe.php',
            $configSymbol,
            SymbolRole::ReadAccess,
        ));
        $this->assertDefinitionAndReference(
            $index,
            'lang/en/scip-acceptance.php',
            'scip-acceptance.messages.welcome',
            'app/Support/AcceptanceTranslationProbe.php',
        );
        $welcomeSymbol = $this->assertDefinitionAndReference(
            $index,
            'lang/en/scip-acceptance.php',
            'scip-acceptance.messages.welcome',
            'resources/views/acceptance/translation-probe.blade.php',
        );
        $pagesDashboardSymbol = $this->assertDefinitionAndReference(
            $index,
            'lang/en/pages.php',
            'pages.dashboard',
            'app/Support/AcceptanceTranslationProbe.php',
        );
        self::assertSame($pagesDashboardSymbol, $this->assertDefinitionAndReference(
            $index,
            'lang/en/pages.php',
            'pages.dashboard',
            'resources/views/acceptance/translation-probe.blade.php',
        ));
        self::assertSame(Kind::Key, $index->findSymbolKindByDisplayName('lang/en/pages.php', 'pages.dashboard'));
        self::assertTrue($index->symbolDocumentationContains(
            'lang/en/pages.php',
            $pagesDashboardSymbol,
            'Laravel translation key: pages.dashboard',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'lang/en/pages.php',
            $pagesDashboardSymbol,
            'Cross-locale model: one logical symbol is shared across lang/<locale>/*.php definitions with the same dotted key.',
        ));
        self::assertSame($pagesDashboardSymbol, $index->findSymbolByDisplayName(
            'lang/de/pages.php',
            'pages.dashboard',
        ));
        self::assertTrue($index->hasOccurrence('lang/de/pages.php', $pagesDashboardSymbol, SymbolRole::Definition));
        $acceptanceJsonKeySymbol = $this->assertDefinitionAndReference(
            $index,
            'lang/en.json',
            'Acceptance JSON Key',
            'app/Support/AcceptanceTranslationProbe.php',
        );
        self::assertSame($acceptanceJsonKeySymbol, $this->assertDefinitionAndReference(
            $index,
            'lang/en.json',
            'Acceptance JSON Key',
            'resources/views/acceptance/translation-probe.blade.php',
        ));
        $settingsSymbol = $this->assertDefinitionAndReference(
            $index,
            'lang/en.json',
            'Settings',
            'app/Support/AcceptanceTranslationProbe.php',
        );
        self::assertSame($settingsSymbol, $this->assertDefinitionAndReference(
            $index,
            'lang/en.json',
            'Settings',
            'resources/views/acceptance/translation-probe.blade.php',
        ));
        self::assertSame(Kind::Key, $index->findSymbolKindByDisplayName('lang/en.json', 'Settings'));
        self::assertTrue($index->symbolDocumentationContains(
            'lang/en.json',
            $settingsSymbol,
            'Laravel translation key: Settings',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'lang/en.json',
            $settingsSymbol,
            'Cross-locale model: one logical symbol is shared across lang/<locale>.json definitions with the same raw key.',
        ));
        self::assertSame($settingsSymbol, $index->findSymbolByDisplayName('lang/de.json', 'Settings'));
        self::assertTrue($index->hasOccurrence('lang/de.json', $settingsSymbol, SymbolRole::Definition));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceTranslationProbe.php',
            $welcomeSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/translation-probe.blade.php',
            $welcomeSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceTranslationProbe.php',
            $pagesDashboardSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/translation-probe.blade.php',
            $pagesDashboardSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceTranslationProbe.php',
            $acceptanceJsonKeySymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceTranslationProbe.php',
            $settingsSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertSame(3, $index->countOccurrencesSymbolContaining(
            'resources/views/acceptance/translation-probe.blade.php',
            $settingsSymbol,
            SymbolRole::ReadAccess,
        ));
        $envSymbol = $this->assertDefinitionAndReference(
            $index,
            '.env',
            'SCIP_ACCEPTANCE_TOKEN',
            'app/Support/AcceptanceEnvProbe.php',
        );
        self::assertSame(Kind::Key, $index->findSymbolKindByDisplayName('.env', 'SCIP_ACCEPTANCE_TOKEN'));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'app/Support/AcceptanceEnvProbe.php',
            $envSymbol,
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->occurrenceOverrideDocumentationContains(
            'app/Support/AcceptanceEnvProbe.php',
            'env/SCIP_ACCEPTANCE_TOKEN.',
            SymbolRole::ReadAccess,
            'Env key: SCIP_ACCEPTANCE_TOKEN',
        ));
        self::assertTrue($index->occurrenceOverrideDocumentationContains(
            'app/Support/AcceptanceTranslationProbe.php',
            'trans/`json:Acceptance JSON Key`.',
            SymbolRole::ReadAccess,
            'Translation key: Acceptance JSON Key',
        ));
    }

    public function test_validation_symbols_are_covered(): void
    {
        $result = $this->executeCliIndex('integration-validation.scip', mode: 'full');
        $index = $this->loadIndex('integration-validation.scip');

        self::assertSame(0, $result->exitCode);
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            'App/Livewire/AcceptanceValidation#$title.',
            'Validation message (required): Title is required.',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            'App/Livewire/AcceptanceValidation#$title.',
            'Validation attribute: headline',
        ));
        self::assertTrue($index->occurrenceOverrideDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            'App/Livewire/AcceptanceValidation#$title.',
            SymbolRole::ReadAccess,
            'Validation key: title',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/Forms/AcceptanceValidationForm.php',
            'App/Livewire/Forms/AcceptanceValidationForm#$title.',
            'Validation message (required): Form title is required.',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/Forms/AcceptanceValidationForm.php',
            'App/Livewire/Forms/AcceptanceValidationForm#$title.',
            'Validation attribute: form headline',
        ));
        self::assertSame(2, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-validation.blade.php',
            'App/Livewire/AcceptanceValidation#$title.',
            SymbolRole::ReadAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-validation.blade.php',
            'App/Livewire/AcceptanceValidation#$title.',
            SymbolRole::WriteAccess,
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-validation.blade.php',
            'App/Livewire/Forms/AcceptanceValidationForm#$title.',
            SymbolRole::ReadAccess,
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            'validation/orphan.',
            'Validation message (required): Orphan is required.',
        ));
        self::assertTrue($index->symbolDocumentationContains(
            'app/Livewire/AcceptanceValidation.php',
            'validation/orphan.',
            'Validation attribute: orphan field',
        ));
        self::assertSame(1, $index->countOccurrencesSymbolContaining(
            'resources/views/livewire/acceptance-validation.blade.php',
            'validation/orphan.',
            SymbolRole::ReadAccess,
        ));
        self::assertFalse($index->documentHasOccurrenceSymbolContaining(
            'app/Livewire/AcceptanceValidation.php',
            'validation/items',
        ));
        self::assertFalse($index->documentHasOccurrenceSymbolContaining(
            'resources/views/livewire/acceptance-validation.blade.php',
            'validation/dynamic',
        ));
    }
}
