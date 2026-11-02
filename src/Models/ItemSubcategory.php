<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * CS2 item subcategory (weapon, knife or gear model).
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum ItemSubcategory: string
{
    case Ak47 = 'AK47';
    case Aug = 'AUG';
    case Awp = 'AWP';
    case Bizon = 'BIZON';
    case C4 = 'C4';
    case Collectible = 'COLLECTIBLE';
    case CustomPlayerBrazilian1stBattalion = 'CUSTOM_PLAYER_BRAZILIAN_1ST_BATTALION';
    case CustomPlayerEliteCrew = 'CUSTOM_PLAYER_ELITE_CREW';
    case CustomPlayerFbi = 'CUSTOM_PLAYER_FBI';
    case CustomPlayerFbiHrt = 'CUSTOM_PLAYER_FBI_HRT';
    case CustomPlayerFbiSniper = 'CUSTOM_PLAYER_FBI_SNIPER';
    case CustomPlayerFbiSwat = 'CUSTOM_PLAYER_FBI_SWAT';
    case CustomPlayerGendarmerieNationale = 'CUSTOM_PLAYER_GENDARMERIE_NATIONALE';
    case CustomPlayerGuerrillaWarfare = 'CUSTOM_PLAYER_GUERRILLA_WARFARE';
    case CustomPlayerKsk = 'CUSTOM_PLAYER_KSK';
    case CustomPlayerNswcSeal = 'CUSTOM_PLAYER_NSWC_SEAL';
    case CustomPlayerNzsas = 'CUSTOM_PLAYER_NZSAS';
    case CustomPlayerPhoenix = 'CUSTOM_PLAYER_PHOENIX';
    case CustomPlayerSabre = 'CUSTOM_PLAYER_SABRE';
    case CustomPlayerSabreFootsoldier = 'CUSTOM_PLAYER_SABRE_FOOTSOLDIER';
    case CustomPlayerSas = 'CUSTOM_PLAYER_SAS';
    case CustomPlayerSealFrogman = 'CUSTOM_PLAYER_SEAL_FROGMAN';
    case CustomPlayerSwat = 'CUSTOM_PLAYER_SWAT';
    case CustomPlayerTacpCavalry = 'CUSTOM_PLAYER_TACP_CAVALRY';
    case CustomPlayerTheProfessionals = 'CUSTOM_PLAYER_THE_PROFESSIONALS';
    case CustomPlayerUsafTacp = 'CUSTOM_PLAYER_USAF_TACP';
    case Cz75a = 'CZ75A';
    case Deagle = 'DEAGLE';
    case Elite = 'ELITE';
    case Famas = 'FAMAS';
    case Fiveseven = 'FIVESEVEN';
    case G3sg1 = 'G3SG1';
    case Galilar = 'GALILAR';
    case Glock = 'GLOCK';
    case GloveBloodhound = 'GLOVE_BLOODHOUND';
    case GloveBrokenFang = 'GLOVE_BROKEN_FANG';
    case GloveFingerless = 'GLOVE_FINGERLESS';
    case GloveHandWraps = 'GLOVE_HAND_WRAPS';
    case GloveHardknuckle = 'GLOVE_HARDKNUCKLE';
    case GloveHydra = 'GLOVE_HYDRA';
    case GloveMotorcycle = 'GLOVE_MOTORCYCLE';
    case GloveSlick = 'GLOVE_SLICK';
    case GloveSpecialist = 'GLOVE_SPECIALIST';
    case GloveSport = 'GLOVE_SPORT';
    case Hkp2000 = 'HKP2000';
    case Knife = 'KNIFE';
    case KnifeBayonet = 'KNIFE_BAYONET';
    case KnifeBowie = 'KNIFE_BOWIE';
    case KnifeButterfly = 'KNIFE_BUTTERFLY';
    case KnifeClassic = 'KNIFE_CLASSIC';
    case KnifeCord = 'KNIFE_CORD';
    case KnifeFalchion = 'KNIFE_FALCHION';
    case KnifeFlip = 'KNIFE_FLIP';
    case KnifeGut = 'KNIFE_GUT';
    case KnifeKarambit = 'KNIFE_KARAMBIT';
    case KnifeKukri = 'KNIFE_KUKRI';
    case KnifeM9Bayonet = 'KNIFE_M9_BAYONET';
    case KnifeNavaja = 'KNIFE_NAVAJA';
    case KnifeNomad = 'KNIFE_NOMAD';
    case KnifeShadowDagger = 'KNIFE_SHADOW_DAGGER';
    case KnifeSkeleton = 'KNIFE_SKELETON';
    case KnifeStiletto = 'KNIFE_STILETTO';
    case KnifeSurvival = 'KNIFE_SURVIVAL';
    case KnifeTactical = 'KNIFE_TACTICAL';
    case KnifeTalon = 'KNIFE_TALON';
    case KnifeUrsus = 'KNIFE_URSUS';
    case M4a1 = 'M4A1';
    case M4a1s = 'M4A1S';
    case M249 = 'M249';
    case Mac10 = 'MAC10';
    case Mag7 = 'MAG7';
    case Mp5sd = 'MP5SD';
    case Mp7 = 'MP7';
    case Mp9 = 'MP9';
    case MusicKit = 'MUSIC_KIT';
    case Negev = 'NEGEV';
    case Nova = 'NOVA';
    case P90 = 'P90';
    case P250 = 'P250';
    case Revolver = 'REVOLVER';
    case Sawedoff = 'SAWEDOFF';
    case Scar20 = 'SCAR20';
    case Sg556 = 'SG556';
    case Spray = 'SPRAY';
    case Ssg08 = 'SSG08';
    case Sticker = 'STICKER';
    case StickerSlab = 'STICKER_SLAB';
    case Taser = 'TASER';
    case Tec9 = 'TEC9';
    case Ticket = 'TICKET';
    case Tool = 'TOOL';
    case ToolGiftTag = 'TOOL_GIFT_TAG';
    case ToolKeyChain = 'TOOL_KEY_CHAIN';
    case ToolNameTagtag = 'TOOL_NAME_TAGTAG';
    case ToolNameTagTag = 'TOOL_NAME_TAG_TAG';
    case ToolPatch = 'TOOL_PATCH';
    case ToolWeaponCaseKeyTag = 'TOOL_WEAPON_CASE_KEY_TAG';
    case Ump45 = 'UMP45';
    case UspSilencer = 'USP_SILENCER';
    case WeaponCase = 'WEAPON_CASE';
    case WeaponCaseCapsule = 'WEAPON_CASE_CAPSULE';
    case WeaponCaseOther = 'WEAPON_CASE_OTHER';
    case WeaponCasePatchPack = 'WEAPON_CASE_PATCH_PACK';
    case WeaponCaseSouvenirPackage = 'WEAPON_CASE_SOUVENIR_PACKAGE';
    case WeaponCaseWeaponCase = 'WEAPON_CASE_WEAPON_CASE';
    case Xm1014 = 'XM1014';
}
