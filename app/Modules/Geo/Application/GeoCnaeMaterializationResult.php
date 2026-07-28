<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Application;

final class GeoCnaeMaterializationResult
{
    public int   $skeletonsCreated       = 0;
    public int   $geoFieldsNormalized    = 0;
    public int   $bairrosMatched         = 0;
    public int   $queuedForEnrichment    = 0;
    public int   $geoFactInserted        = 0;
    public int   $geoDailyInserted       = 0;
    public int   $compradoresGeoInserted = 0;
    public int   $vendasCnaeInserted     = 0;
    /** @var list<string> */
    public array $warnings               = [];
}
