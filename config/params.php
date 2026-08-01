<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-ab-testing-outbox' => [
        // Aggregate ids are pseudonymous; without a secret they are guessable
        // offline from an experiment name and a subject id.
        'aggregateIdSecret' => '',
    ],
];
