<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Team Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Team\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Widget\PositionWidgets;
use Uhifadhi\Widget\Service\WidgetEndpoint;
use Uhifadhi\Widget\Service\WidgetService;

/**
 * THE WIDGET LIBRARY for the permission-matrix surface.
 *
 * THE PAGE IS CHROME; everything inside it is the widget module's shared preset
 * component, handed this surface's catalogue, this surface's partial name and
 * this surface's routes. There are no team-specific widget mechanics anywhere,
 * which is the whole point of riding the framework: adopting a direction here
 * works exactly as it does on every other surface in the installation.
 *
 * EVERY WRITE IS ANSWERED BY {@see WidgetEndpoint}. This controller validates
 * nothing itself, mints no token and chooses no status code — it names the
 * catalogue and turns a 204 into a redirect with a sentence, so the plain-form
 * path works with no JavaScript at all.
 *
 * ORG-WIDE, SO NO AREA UUID. Positions belong to the installation rather than
 * to any one area, so there is no per-area version of this page to lay out
 * differently and every framework call passes null for the area.
 */
final readonly class PositionWidgetsController
{
    /** A structurally valid uuid that addresses nothing — see {@see urls()}. */
    private const string PLACEHOLDER_UUID = '00000000-0000-4000-8000-000000000000';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private WidgetService $widgets,
        private WidgetEndpoint $endpoint,
        private PositionController $positions,
    ) {
    }

    #[Route('/team/positions/widgets', name: 'team_position_widgets', methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function library(Request $request): Response
    {
        $catalog = new PositionWidgets()->catalog();
        $user = $this->endpoint->user();

        return new Response($this->twig->render('@UhifadhiTeam/widgets/positions.html.twig', [
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $user),
            'active' => $this->widgets->activeRef($catalog, $user),
            'widgets' => $this->widgets->resolve($catalog, $user),
            'partial' => '@UhifadhiTeam/positions/_w_%s.html.twig',
            // EVERY PARTIAL RENDERS THE REAL WIDGET ON REAL DATA, at full size.
            // The picture of a widget IS the widget, so what you arrange is
            // exactly what you get.
            'widgetContext' => $this->positions->widgetContext($request),
            'urls' => $this->urls(),
            'csrfToken' => $this->endpoint->csrfToken($catalog),
        ]));
    }

    #[Route('/team/positions/widgets/save', name: 'team_position_widgets_save', methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function save(Request $request): Response
    {
        return $this->endpoint->save($request, new PositionWidgets()->catalog());
    }

    #[Route('/team/positions/widgets/reset', name: 'team_position_widgets_reset', methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function reset(Request $request): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->reset($request, new PositionWidgets()->catalog()),
            'Your matrix is back to the direction this module ships with.',
        );
    }

    #[Route('/team/positions/widgets/preset/{presetId}', name: 'team_position_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function applyPreset(Request $request, string $presetId): Response
    {
        $catalog = new PositionWidgets()->catalog();
        // A design the surface does not ship is refused by the endpoint; naming
        // it in the flash is only for the case where it IS shipped.
        $adopted = $catalog->preset($presetId);

        return $this->afterWrite(
            $request,
            $this->endpoint->applyPreset($request, $catalog, $presetId),
            \sprintf('Your matrix now follows “%s”.', null !== $adopted ? $adopted->label : $presetId),
        );
    }

    #[Route('/team/positions/widgets/preset/{presetId}/copy', name: 'team_position_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 1)]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function copyPreset(Request $request, string $presetId): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->copyPreset($request, new PositionWidgets()->catalog(), $presetId),
            'Copied — the copy is yours to edit, and the design it came from is untouched.',
        );
    }

    #[Route('/team/positions/widgets/presets', name: 'team_position_widgets_preset_create', methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function createPreset(Request $request): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->createCustomPreset($request, new PositionWidgets()->catalog()),
            'Saved — this arrangement is now one of your own designs.',
        );
    }

    #[Route('/team/positions/widgets/presets/{presetUuid}/apply', name: 'team_position_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function applyCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->applyCustomPreset($request, new PositionWidgets()->catalog(), Uuid::fromString($presetUuid)),
            'Your design is on.',
        );
    }

    #[Route('/team/positions/widgets/presets/{presetUuid}/rename', name: 'team_position_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function renameCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->renameCustomPreset($request, new PositionWidgets()->catalog(), Uuid::fromString($presetUuid)),
            'Renamed.',
        );
    }

    #[Route('/team/positions/widgets/presets/{presetUuid}/delete', name: 'team_position_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function deleteCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->deleteCustomPreset($request, new PositionWidgets()->catalog(), Uuid::fromString($presetUuid)),
            'Design deleted. Your matrix is back on the direction this module ships with.',
        );
    }

    /**
     * The library's action URLs, with two PLACEHOLDERS the browser substitutes:
     * `__ID__` for a built-in preset's id, and a uuid for a saved one.
     *
     * THE PLACEHOLDER UUID HAS TO BE A VALID UUID. The routes constrain it with
     * Requirement::UUID, and a router asked to generate a URL from a value the
     * route refuses THROWS — so the obvious nil uuid takes the whole page down
     * at render time rather than at click time. It is a v4-shaped nil instead:
     * structurally valid, and addressing nothing.
     *
     * @return array<string, string>
     */
    private function urls(): array
    {
        return [
            'save' => $this->router->generate('team_position_widgets_save'),
            'reset' => $this->router->generate('team_position_widgets_reset'),
            'preset' => $this->router->generate('team_position_widgets_preset', ['presetId' => '__ID__']),
            'copy' => $this->router->generate('team_position_widgets_preset_copy', ['presetId' => '__ID__']),
            'presets' => $this->router->generate('team_position_widgets_preset_create'),
            'apply' => $this->router->generate('team_position_widgets_preset_apply', ['presetUuid' => self::PLACEHOLDER_UUID]),
            'rename' => $this->router->generate('team_position_widgets_preset_rename', ['presetUuid' => self::PLACEHOLDER_UUID]),
            'delete' => $this->router->generate('team_position_widgets_preset_delete', ['presetUuid' => self::PLACEHOLDER_UUID]),
            'dashboard' => $this->router->generate('team_positions'),
        ];
    }

    /**
     * A refused write is returned as it came (the library's fetch() reads the
     * status and the message); a successful one says so and goes back to the
     * library, so the plain-form path works with no JavaScript at all.
     */
    private function afterWrite(Request $request, Response $response, string $flash): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return new RedirectResponse($this->router->generate('team_position_widgets'));
    }
}
