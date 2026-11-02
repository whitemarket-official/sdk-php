<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * The avatar a store shows (`PersonStore.avatar`, `StoreSettings::$avatar`). A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum StoreAvatar: string
{
    case Avatar1 = 'AVATAR_1';
    case Avatar2 = 'AVATAR_2';
    case Avatar3 = 'AVATAR_3';
    case Avatar4 = 'AVATAR_4';
    case Avatar5 = 'AVATAR_5';
    case Avatar6 = 'AVATAR_6';
    case Avatar7 = 'AVATAR_7';
    case Avatar8 = 'AVATAR_8';
    case Avatar9 = 'AVATAR_9';
    case Avatar10 = 'AVATAR_10';
    case Avatar11 = 'AVATAR_11';
    case Avatar12 = 'AVATAR_12';
    case Custom = 'CUSTOM';
    case Steam = 'STEAM';
}
