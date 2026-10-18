<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Pages\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\AlfakitOps\OpsPlugin;

/**
 * Shared by the operations pages: access through the plugin's authorisation and feature
 * toggles, translated titles and navigation labels, and action authorisation.
 *
 * @phpstan-require-extends \AlfaSix\Alfakit\Pages\Page
 */
trait InteractsWithOps
{
    /**
     * The `OpsPlugin::FEATURE_*` this page belongs to.
     */
    abstract public static function getOpsFeature(): string;

    /**
     * The key of the page's strings in `alfakit-ops::ops` (`failed_jobs`).
     */
    abstract protected static function getOpsTranslationKey(): string;

    public static function getPlugin(): OpsPlugin
    {
        return OpsPlugin::get();
    }

    public static function canAccess(): bool
    {
        $plugin = static::getPlugin();

        return $plugin->isAuthorized() && $plugin->hasFeature(static::getOpsFeature());
    }

    /**
     * Whether the current user may perform `$ability` (`OpsPlugin::ABILITY_*`).
     */
    public static function can(string $ability): bool
    {
        return static::canAccess() && static::getPlugin()->can($ability);
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-ops::ops.'.static::getOpsTranslationKey().'.title');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-ops::ops.'.static::getOpsTranslationKey().'.navigation_label');
    }

    /**
     * Authorise an action for `$ability`, and ask for the password first when the plugin
     * `requiresPasswordConfirmation()` and the action is destructive.
     *
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @return TAction
     */
    protected static function authorizeOpsAction(Action $action, string $ability, bool $isDestructive = false): Action
    {
        $action->authorize(static fn (): bool => static::can($ability));

        if ($isDestructive && static::getPlugin()->shouldRequirePasswordConfirmation()) {
            $action->requiresPasswordConfirmation();
        }

        return $action;
    }
}
