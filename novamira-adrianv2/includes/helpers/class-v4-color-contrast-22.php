<?php
declare(strict_types=1);
/**
 * WCAG 2.2 Color Contrast Helper — BC Extension (v1.3.0)
 *
 * Extends V4_Color_Contrast. Since v1.3.0, all WCAG 2.2 methods and
 * constants have been merged into the base class. This class exists
 * for backward compatibility only; new code should use V4_Color_Contrast
 * directly.
 *
 * @deprecated 1.3.0 Use V4_Color_Contrast directly.
 * @since      1.1.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) exit();

/**
 * @deprecated 1.3.0 Use V4_Color_Contrast — all WCAG 2.2 features merged there.
 */
class V4_Color_Contrast_22 extends V4_Color_Contrast {
    // All WCAG 2.2 constants inherited from V4_Color_Contrast:
    //   TARGET_SIZE_MIN = 24
    //   FOCUS_APPEARANCE_CONTRAST = 3.0

    // All WCAG 2.2 methods inherited from V4_Color_Contrast:
    //   passes_target_size($w, $h)
    //   passes_focus_appearance($focus, $bg)
    //   contrast_ratio($hex1, $hex2) — unified implementation
    //   relative_luminance($rgb)     — unified implementation
}
