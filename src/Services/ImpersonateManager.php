<?php

namespace EvoMark\Impersonate\Services;

use Exception;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use EvoMark\Impersonate\Events\LeaveImpersonation;
use EvoMark\Impersonate\Events\TakeImpersonation;
use EvoMark\Impersonate\Exceptions\InvalidUserProvider;
use EvoMark\Impersonate\Exceptions\MissingUserProvider;

class ImpersonateManager
{
    const REMEMBER_PREFIX = 'remember_web';

    /** @var Application $app */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param int $id
     * @return \Illuminate\Contracts\Auth\Authenticatable
     * @throws MissingUserProvider
     * @throws InvalidUserProvider
     * @throws ModelNotFoundException
     */
    public function findUserById($id, $guardName = null)
    {
        if (empty($guardName)) {
            $guardName = $this->app['config']->get('auth.default.guard', 'web');
        }

        $providerName = $this->app['config']->get("auth.guards.$guardName.provider");

        if (empty($providerName)) {
            throw new MissingUserProvider($guardName);
        }

        try {
            /** @var UserProvider $userProvider */
            $userProvider = $this->app['auth']->createUserProvider($providerName);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidUserProvider($guardName);
        }

        if (!($modelInstance = $userProvider->retrieveById($id))) {
            $model = $this->app['config']->get("auth.providers.$providerName.model");

            throw (new ModelNotFoundException())->setModel(
                $model,
                $id
            );
        }

        return $modelInstance;
    }

    public function isImpersonating(): bool
    {
        return session()->has($this->getSessionKey());
    }

    /**
     * @return  int|null
     */
    public function getImpersonatorId()
    {
        return session($this->getSessionKey(), null);
    }

    /**
     * @return \Illuminate\Contracts\Auth\Authenticatable
     */
    public function getImpersonator()
    {
        $id = session($this->getSessionKey(), null);

        return is_null($id) ? null : $this->findUserById($id, $this->getImpersonatorGuardName());
    }

    /**
     * @return string|null
     */
    public function getImpersonatorGuardName()
    {
        return session($this->getSessionGuard(), null);
    }

    /**
     * @return string|null
     */
    public function getImpersonatorGuardUsingName()
    {
        return session($this->getSessionGuardUsing(), null);
    }

    /**
     * @param \Illuminate\Contracts\Auth\Authenticatable $from
     * @param \Illuminate\Contracts\Auth\Authenticatable $to
     * @param string|null                         $guardName
     * @return bool
     */
    public function take($from, $to, $guardName = null)
    {
        $this->saveAuthCookieInSession();

        try {
            $currentGuard = $this->getCurrentAuthGuardName();
            session()->put($this->getSessionKey(), $from->getAuthIdentifier());
            session()->put($this->getSessionGuard(), $currentGuard);
            session()->put($this->getSessionGuardUsing(), $guardName);

            $this->app['auth']->guard($currentGuard)->quietLogout();
            $this->app['auth']->guard($guardName)->quietLogin($to);

        } catch (\Exception $e) {
            unset($e);
            return false;
        }

        $this->app['events']->dispatch(new TakeImpersonation($from, $to));

        return true;
    }

    public function leave(): bool
    {
        try {
            $impersonated = $this->app['auth']->guard($this->getImpersonatorGuardUsingName())->user();
            $impersonator = $this->findUserById($this->getImpersonatorId(), $this->getImpersonatorGuardName());

            $this->app['auth']->guard($this->getCurrentAuthGuardName())->quietLogout();
            $this->app['auth']->guard($this->getImpersonatorGuardName())->quietLogin($impersonator);

            $this->extractAuthCookieFromSession();

            $this->clear();

        } catch (\Exception $e) {
            unset($e);
            return false;
        }

        $this->app['events']->dispatch(new LeaveImpersonation($impersonator, $impersonated));

        return true;
    }

    public function clear()
    {
        session()->forget($this->getSessionKey());
        session()->forget($this->getSessionGuard());
        session()->forget($this->getSessionGuardUsing());
    }

    public function getSessionKey(): string
    {
        return config('laravel-impersonate.session_key');
    }

    public function getSessionGuard(): string
    {
        return config('laravel-impersonate.session_guard');
    }

    public function getSessionGuardUsing(): string
    {
        return config('laravel-impersonate.session_guard_using');
    }

    public function getDefaultSessionGuard(): string
    {
        return config('laravel-impersonate.default_impersonator_guard');
    }

    public function getTakeRedirectTo($userToImpersonate): string
    {
        $takeRedirectToConfig = config('laravel-impersonate.take_redirect_to');
        if (is_callable($takeRedirectToConfig)) {
            $takeRedirectToConfig = call_user_func($takeRedirectToConfig, $userToImpersonate);
        }
        try {
            $uri = route($takeRedirectToConfig);
        } catch (\InvalidArgumentException $e) {
            $uri = $takeRedirectToConfig;
        }

        return $uri;
    }

    public function getLeaveRedirectTo(): string
    {
        $leaveRedirectToConfig = config('laravel-impersonate.leave_redirect_to');
        if (is_callable($leaveRedirectToConfig)) {
            $leaveRedirectToConfig = call_user_func($leaveRedirectToConfig);
        }
        try {
            $uri = route($leaveRedirectToConfig);
        } catch (\InvalidArgumentException $e) {
            $uri = $leaveRedirectToConfig;
        }

        return $uri;
    }

    /**
     * @return array|null
     */
    public function getCurrentAuthGuardName()
    {
        $guards = array_keys(config('auth.guards'));

        foreach ($guards as $guard) {
            if ($this->app['auth']->guard($guard)->check()) {
                return $guard;
            }
        }

        return null;
    }

    protected function saveAuthCookieInSession(): void
    {
        $cookie = $this->findByKeyInArray($this->app['request']->cookies->all(), static::REMEMBER_PREFIX);
        $key = $cookie->keys()->first();
        $val = $cookie->values()->first();

        if (!$key || !$val) {
            return;
        }

        session()->put(static::REMEMBER_PREFIX, [
            $key,
            $val,
        ]);
    }

    protected function extractAuthCookieFromSession(): void
    {
        if (!$session = $this->findByKeyInArray(session()->all(), static::REMEMBER_PREFIX)->first()) {
            return;
        }

        $this->app['cookie']->queue($session[0], $session[1]);
        session()->forget($session);
    }

    /**
     * @param array  $values
     * @param string $search
     * @return \Illuminate\Support\Collection
     */
    protected function findByKeyInArray(array $values, string $search)
    {
        return collect($values ?? session()->all())
            ->filter(function ($val, $key) use ($search) {
                return strpos($key, $search) !== false;
            });
    }
}
