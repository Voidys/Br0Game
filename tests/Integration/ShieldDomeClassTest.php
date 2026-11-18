<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\GameTestCase;

/**
 * The shield domes are defence the shipyard builds (they sat in the buildings group with class 0 and the class in
 * ship_type, so nobody could build them); the special defence 409-411 stays out of every buildable group.
 */
final class ShieldDomeClassTest extends GameTestCase
{
    public function testShieldDomesAreDefence(): void
    {
        $defense = $this->elements()->ids('defense');
        $this->assertContains(407, $defense);
        $this->assertContains(408, $defense);
        $this->assertContains(407, $this->elements()->ids('one'), 'one dome per planet');

        foreach ([409, 410, 411] as $id) {
            $this->assertNotContains($id, $defense);
            $this->assertNotContains($id, $this->elements()->ids('build'));
        }
        foreach ([407, 408] as $id) {
            $this->assertNotContains($id, $this->elements()->ids('build'));
        }
    }
}
