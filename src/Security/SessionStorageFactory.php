<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/** Session storage with gc_maxlifetime from the admin setting session_lifetime (SessionPolicy) instead of a fixed value. */
#[AsDecorator('session.storage.factory.native')]
final class SessionStorageFactory implements SessionStorageFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly SessionStorageFactoryInterface $inner,
        private readonly SessionPolicy $policy,
    ) {}

    /** The native storage of the decorated factory with gc_maxlifetime set to the idle limit of SessionPolicy. */
    public function createStorage(?Request $request): SessionStorageInterface
    {
        $storage = $this->inner->createStorage($request);
        if ($storage instanceof NativeSessionStorage) {
            $storage->setOptions(['gc_maxlifetime' => $this->policy->idleLifetime()]);
        }

        return $storage;
    }
}
