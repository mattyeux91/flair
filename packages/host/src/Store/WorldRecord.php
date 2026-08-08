<?php

declare(strict_types=1);

namespace Flair\Host\Store;

/**
 * L'identite d'un monde en base : sa graine, le couple auquel il est epingle
 * (docs/12- §6), et le tick ou il en est.
 *
 * `tick` est une **projection de commodite** - de quoi repondre a « ou en
 * sont mes mondes » sans deserialiser un snapshot de plusieurs centaines de
 * kilo-octets. La verite reste le tick du dernier snapshot, ecrit dans la
 * meme transaction que celui-ci ; les deux ne peuvent donc pas diverger.
 */
final readonly class WorldRecord
{
    public function __construct(
        public string $id,
        public int $seed,
        public string $kernelVersion,
        public string $rulesetVersion,
        public int $tick,
    ) {
    }
}
