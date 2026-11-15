<?php

namespace App\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read access to the installer-written settings in config/game.php (wiki SSO, salt, ...),
 * for Symfony code that runs without the game bootstrap.
 */
final class GameSettings
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    public function get(string $name, mixed $default = null): mixed
    {
        if ($this->values === null) {
            $file = $this->projectDir . '/config/game.php';
            $this->values = is_file($file)
                ? (static function (string $file): array {
                    require $file;

                    return get_defined_vars();
                })($file)
                : [];
        }

        return $this->values[$name] ?? $default;
    }
}
