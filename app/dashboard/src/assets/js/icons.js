/**
 * Petitstats Lucide Icons Helper
 * Provides crisp SVG icons powered by Lucide with zero-dependency built-in fallbacks.
 */

const Icons = {
  // Built-in SVG paths matching Lucide definitions
  svgs: {
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    "user-check":
      '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/>',
    "file-text":
      '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    banknote: '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
    activity:
      '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.48 12H2"/>',
    globe:
      '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    monitor:
      '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
    smartphone:
      '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
    tablet:
      '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><line x1="12" x2="12.01" y1="18" y2="18"/>',
    laptop:
      '<path d="M20 16V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v9m16 0H4m16 0 1.28 2.55a1 1 0 0 1-.9 1.45H3.62a1 1 0 0 1-.9-1.45L4 16"/>',
    clock:
      '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    calendar:
      '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
    tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    compass:
      '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>',
    "external-link":
      '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    "check-circle":
      '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    "chevron-right": '<path d="m9 18 6-6-6-6"/>',
    "chevron-down": '<path d="m6 9 6 6 6-6"/>',
    "arrow-right": '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    filter: '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
    x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    users:
      '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    eye: '<path d="M2.062 12.348a1.3 1.3 0 0 1 0-.696C3.235 7.584 7.247 5 12 5s8.765 2.584 9.938 6.652a1.3 1.3 0 0 1 0 .696C20.765 16.416 16.753 19 12 19S3.235 16.416 2.062 12.348"/><circle cx="12" cy="12" r="3"/>',
    repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
    "eye-off":
      '<path d="m2 2 20 20"/><path d="M6.71 6.71C4.93 7.89 3.44 9.58 2.62 11.65a1.3 1.3 0 0 0 0 .7C3.79 15.32 7.72 18 12 18c1.1 0 2.15-.18 3.12-.5"/><path d="M10.73 5.08A10.7 10.7 0 0 1 12 5c4.75 0 8.76 2.58 9.94 6.65a1.3 1.3 0 0 1 0 .7 11.4 11.4 0 0 1-3.05 4.6"/><path d="M14.12 14.12A3 3 0 0 1 9.88 9.88"/>',
    terminal:
      '<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>',
    cpu: '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
    "mouse-pointer-click":
      '<path d="m9 9 5 12 1.774-5.226L21 14 9 9z"/><path d="m16.071 7.929 1.414-1.414"/><path d="M19 13h2"/><path d="M14 4V2"/><path d="M9 13H7"/><path d="m7.929 7.929-1.414-1.414"/>',
    settings:
      '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
    "message-circle": '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    "layout-grid": '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    "key-round": '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
    "arrow-up-right": '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    bot: '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    plug: '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
  },

  /**
   * Filled brand logos from Simple Icons (CC0), used to identify the AI
   * assistants that connect to the MCP server. OpenAI and VS Code come from
   * the last releases that still shipped them (15.0.0 and 10.0.0).
   */
  brands: {
    "claude": "m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z",
    "cursor": "M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23",
    "windsurf": "M23.55 5.067c-1.2038-.002-2.1806.973-2.1806 2.1765v4.8676c0 .972-.8035 1.7594-1.7597 1.7594-.568 0-1.1352-.286-1.4718-.7659l-4.9713-7.1003c-.4125-.5896-1.0837-.941-1.8103-.941-1.1334 0-2.1533.9635-2.1533 2.153v4.8957c0 .972-.7969 1.7594-1.7596 1.7594-.57 0-1.1363-.286-1.4728-.7658L.4076 5.1598C.2822 4.9798 0 5.0688 0 5.2882v4.2452c0 .2147.0656.4228.1884.599l5.4748 7.8183c.3234.462.8006.8052 1.3509.9298 1.3771.313 2.6446-.747 2.6446-2.0977v-4.893c0-.972.7875-1.7593 1.7596-1.7593h.003a1.798 1.798 0 0 1 1.4718.7658l4.9723 7.0994c.4135.5905 1.05.941 1.8093.941 1.1587 0 2.1515-.9645 2.1515-2.153v-4.8948c0-.972.7875-1.7594 1.7596-1.7594h.194a.22.22 0 0 0 .2204-.2202v-4.622a.22.22 0 0 0-.2203-.2203Z",
    "openai": "M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z",
    "vscode": "M23.15 2.587L18.21.21a1.494 1.494 0 0 0-1.705.29l-9.46 8.63-4.12-3.128a.999.999 0 0 0-1.276.057L.327 7.261A1 1 0 0 0 .326 8.74L3.899 12 .326 15.26a1 1 0 0 0 .001 1.479L1.65 17.94a.999.999 0 0 0 1.276.057l4.12-3.128 9.46 8.63a1.492 1.492 0 0 0 1.704.29l4.942-2.377A1.5 1.5 0 0 0 24 20.06V3.939a1.5 1.5 0 0 0-.85-1.352zm-5.146 14.861L10.826 12l7.178-5.448v10.896z",
    "gemini": "M11.04 19.32Q12 21.51 12 24q0-2.49.93-4.68.96-2.19 2.58-3.81t3.81-2.55Q21.51 12 24 12q-2.49 0-4.68-.93a12.3 12.3 0 0 1-3.81-2.58 12.3 12.3 0 0 1-2.58-3.81Q12 2.49 12 0q0 2.49-.96 4.68-.93 2.19-2.55 3.81a12.3 12.3 0 0 1-3.81 2.58Q2.49 12 0 12q2.49 0 4.68.96 2.19.93 3.81 2.55t2.55 3.81"
  },

  brand(name, { size = 20, className = "" } = {}) {
    const path = this.brands[name];
    if (!path) return "";
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="currentColor" class="${className}" aria-hidden="true"><path d="${path}"/></svg>`;
  },

  get(
    name,
    { size = 16, strokeWidth = 2, className = "", color = "currentColor" } = {},
  ) {
    // If official Lucide library is present
    if (window.lucide && window.lucide.icons && window.lucide.icons[name]) {
      try {
        return window.lucide.icons[name].toSvg({
          width: size,
          height: size,
          "stroke-width": strokeWidth,
          class: className,
          color: color,
        });
      } catch (e) {
        // fallback
      }
    }

    // SVG fallback
    const inner =
      this.svgs[name] ||
      this.svgs["activity"] ||
      '<circle cx="12" cy="12" r="9"/>';
    return `
            <svg 
                xmlns="http://www.w3.org/2000/svg" 
                width="${size}" 
                height="${size}" 
                viewBox="0 0 24 24" 
                fill="none" 
                stroke="${color}" 
                stroke-width="${strokeWidth}" 
                stroke-linecap="round" 
                stroke-linejoin="round" 
                class="lucide lucide-${name} ${className}"
            >
                ${inner}
            </svg>
        `;
  },

  // Compact deterministic MD5 algorithm for Gravatar hashing
  md5(string) {
    function rotateLeft(lValue, iShiftBits) {
      return (lValue << iShiftBits) | (lValue >>> (32 - iShiftBits));
    }
    function addUnsigned(lX, lY) {
      var lX4, lY4, lX8, lY8, lResult;
      lX8 = lX & 0x80000000;
      lY8 = lY & 0x80000000;
      lX4 = lX & 0x40000000;
      lY4 = lY & 0x40000000;
      lResult = (lX & 0x3fffffff) + (lY & 0x3fffffff);
      if (lX4 & lY4) return lResult ^ 0x80000000 ^ lX8 ^ lY8;
      if (lX4 | lY4) {
        if (lResult & 0x40000000) return lResult ^ 0xc0000000 ^ lX8 ^ lY8;
        else return lResult ^ 0x40000000 ^ lX8 ^ lY8;
      } else {
        return lResult ^ lX8 ^ lY8;
      }
    }
    function F(x, y, z) {
      return (x & y) | (~x & z);
    }
    function G(x, y, z) {
      return (x & z) | (y & ~z);
    }
    function H(x, y, z) {
      return x ^ y ^ z;
    }
    function I(x, y, z) {
      return y ^ (x | ~z);
    }
    function FF(a, b, c, d, x, s, ac) {
      a = addUnsigned(a, addUnsigned(addUnsigned(F(b, c, d), x), ac));
      return addUnsigned(rotateLeft(a, s), b);
    }
    function GG(a, b, c, d, x, s, ac) {
      a = addUnsigned(a, addUnsigned(addUnsigned(G(b, c, d), x), ac));
      return addUnsigned(rotateLeft(a, s), b);
    }
    function HH(a, b, c, d, x, s, ac) {
      a = addUnsigned(a, addUnsigned(addUnsigned(H(b, c, d), x), ac));
      return addUnsigned(rotateLeft(a, s), b);
    }
    function II(a, b, c, d, x, s, ac) {
      a = addUnsigned(a, addUnsigned(addUnsigned(I(b, c, d), x), ac));
      return addUnsigned(rotateLeft(a, s), b);
    }
    function convertToWordArray(string) {
      var lWordCount;
      var lMessageLength = string.length;
      var lNumberOfWords_temp1 = lMessageLength + 8;
      var lNumberOfWords_temp2 =
        (lNumberOfWords_temp1 - (lNumberOfWords_temp1 % 64)) / 64;
      var lNumberOfWords = (lNumberOfWords_temp2 + 1) * 16;
      var lWordArray = Array(lNumberOfWords - 1);
      var lBytePosition = 0;
      var lByteCount = 0;
      while (lByteCount < lMessageLength) {
        lWordCount = (lByteCount - (lByteCount % 4)) / 4;
        lBytePosition = (lByteCount % 4) * 8;
        lWordArray[lWordCount] =
          lWordArray[lWordCount] |
          (string.charCodeAt(lByteCount) << lBytePosition);
        lByteCount++;
      }
      lWordCount = (lByteCount - (lByteCount % 4)) / 4;
      lBytePosition = (lByteCount % 4) * 8;
      lWordArray[lWordCount] = lWordArray[lWordCount] | (0x80 << lBytePosition);
      lWordArray[lNumberOfWords - 2] = lMessageLength << 3;
      lWordArray[lNumberOfWords - 1] = lMessageLength >>> 29;
      return lWordArray;
    }
    function wordToHex(lValue) {
      var WordToHexValue = "",
        WordToHexValue_temp = "",
        lByte,
        lCount;
      for (lCount = 0; lCount <= 3; lCount++) {
        lByte = (lValue >>> (lCount * 8)) & 255;
        WordToHexValue_temp = "0" + lByte.toString(16);
        WordToHexValue =
          WordToHexValue +
          WordToHexValue_temp.substr(WordToHexValue_temp.length - 2, 2);
      }
      return WordToHexValue;
    }
    var x = convertToWordArray(string);
    var k,
      AA,
      BB,
      CC,
      DD,
      a = 0x67452301,
      b = 0xefcdab89,
      c = 0x98badcfe,
      d = 0x10325476;
    var S11 = 7,
      S12 = 12,
      S13 = 17,
      S14 = 22;
    var S21 = 5,
      S22 = 9,
      S23 = 14,
      S24 = 20;
    var S31 = 4,
      S32 = 11,
      S33 = 16,
      S34 = 23;
    var S41 = 6,
      S42 = 10,
      S43 = 15,
      S44 = 21;
    for (k = 0; k < x.length; k += 16) {
      AA = a;
      BB = b;
      CC = c;
      DD = d;
      a = FF(a, b, c, d, x[k + 0], S11, 0xd76aa478);
      d = FF(d, a, b, c, x[k + 1], S12, 0xe8c7b756);
      c = FF(c, d, a, b, x[k + 2], S13, 0x242070db);
      b = FF(b, c, d, a, x[k + 3], S14, 0xc1bdceee);
      a = FF(a, b, c, d, x[k + 4], S11, 0xf57c0faf);
      d = FF(d, a, b, c, x[k + 5], S12, 0x4787c62a);
      c = FF(c, d, a, b, x[k + 6], S13, 0xa8304613);
      b = FF(b, c, d, a, x[k + 7], S14, 0xfd469501);
      a = FF(a, b, c, d, x[k + 8], S11, 0x698098d8);
      d = FF(d, a, b, c, x[k + 9], S12, 0x8b44f7af);
      c = FF(c, d, a, b, x[k + 10], S13, 0xffff5bb1);
      b = FF(b, c, d, a, x[k + 11], S14, 0x895cd7be);
      a = FF(a, b, c, d, x[k + 12], S11, 0x6b901122);
      d = FF(d, a, b, c, x[k + 13], S12, 0xfd987193);
      c = FF(c, d, a, b, x[k + 14], S13, 0xa679438e);
      b = FF(b, c, d, a, x[k + 15], S14, 0x49b40821);
      a = GG(a, b, c, d, x[k + 1], S21, 0xf61e2562);
      d = GG(d, a, b, c, x[k + 6], S22, 0xc040b340);
      c = GG(c, d, a, b, x[k + 11], S23, 0x265e5a51);
      b = GG(b, c, d, a, x[k + 0], S24, 0xe9b6c7aa);
      a = GG(a, b, c, d, x[k + 5], S21, 0xd62f105d);
      d = GG(d, a, b, c, x[k + 10], S22, 0x2441453);
      c = GG(c, d, a, b, x[k + 15], S23, 0xd8a1e681);
      b = GG(b, c, d, a, x[k + 4], S24, 0xe7d3fbc8);
      a = GG(a, b, c, d, x[k + 9], S21, 0x21e1cde6);
      d = GG(d, a, b, c, x[k + 14], S22, 0xc33707d6);
      c = GG(c, d, a, b, x[k + 3], S23, 0xf4d50d87);
      b = GG(b, c, d, a, x[k + 8], S24, 0x455a14ed);
      a = GG(a, b, c, d, x[k + 13], S21, 0xa9e3e905);
      d = GG(d, a, b, c, x[k + 2], S22, 0xfcefa3f8);
      c = GG(c, d, a, b, x[k + 7], S23, 0x676f02d9);
      b = GG(b, c, d, a, x[k + 12], S24, 0x8d2a4c8a);
      a = HH(a, b, c, d, x[k + 5], S31, 0xfffa3942);
      d = HH(d, a, b, c, x[k + 8], S32, 0x8771f681);
      c = HH(c, d, a, b, x[k + 11], S33, 0x6d9d6122);
      b = HH(b, c, d, a, x[k + 14], S34, 0xfde5380c);
      a = HH(a, b, c, d, x[k + 1], S31, 0xa4beea44);
      d = HH(d, a, b, c, x[k + 4], S32, 0x4bdecfa9);
      c = HH(c, d, a, b, x[k + 7], S33, 0xf6bb4b60);
      b = HH(b, c, d, a, x[k + 10], S34, 0xbebfbc70);
      a = HH(a, b, c, d, x[k + 13], S31, 0x289b7ec6);
      d = HH(d, a, b, c, x[k + 0], S32, 0xeaa127fa);
      c = HH(c, d, a, b, x[k + 3], S33, 0xd4ef3085);
      b = HH(b, c, d, a, x[k + 6], S34, 0x4881d05);
      a = HH(a, b, c, d, x[k + 9], S31, 0xd9d4d039);
      d = HH(d, a, b, c, x[k + 12], S32, 0xe6db99e5);
      c = HH(c, d, a, b, x[k + 15], S33, 0x1fa27cf8);
      b = HH(b, c, d, a, x[k + 2], S34, 0xc4ac5665);
      a = II(a, b, c, d, x[k + 0], S41, 0xf4292244);
      d = II(d, a, b, c, x[k + 7], S42, 0x432aff97);
      c = II(c, d, a, b, x[k + 14], S43, 0xab9423a7);
      b = II(b, c, d, a, x[k + 5], S44, 0xfc93a039);
      a = II(a, b, c, d, x[k + 12], S41, 0x655b59c3);
      d = II(d, a, b, c, x[k + 3], S42, 0x8f0ccc92);
      c = II(c, d, a, b, x[k + 10], S43, 0xffeff47d);
      b = II(b, c, d, a, x[k + 1], S44, 0x85845dd1);
      a = II(a, b, c, d, x[k + 8], S41, 0x6fa87e4f);
      d = II(d, a, b, c, x[k + 15], S42, 0xfe2ce6e0);
      c = II(c, d, a, b, x[k + 6], S43, 0xa3014314);
      b = II(b, c, d, a, x[k + 13], S44, 0x4e0811a1);
      a = II(a, b, c, d, x[k + 4], S41, 0xf7537e82);
      d = II(d, a, b, c, x[k + 11], S42, 0xbd3af235);
      c = II(c, d, a, b, x[k + 2], S43, 0x2ad7d2bb);
      b = II(b, c, d, a, x[k + 9], S44, 0xeb86d391);
      a = addUnsigned(a, AA);
      b = addUnsigned(b, BB);
      c = addUnsigned(c, CC);
      d = addUnsigned(d, DD);
    }
    return (
      wordToHex(a) +
      wordToHex(b) +
      wordToHex(c) +
      wordToHex(d)
    ).toLowerCase();
  },

  // DiceBear 10.x Glyphs avatar URL generator (free, public SVG glyphs)
  getDiceBearGlyphUrl(sessionId) {
    const seed = encodeURIComponent(String(sessionId || "session"));
    return `https://api.dicebear.com/10.x/shadows/svg?seed=${seed}`;
  },

  getAvatarUrl(sessionId) {
    return this.getDiceBearGlyphUrl(sessionId);
  },

  getGravatarUrl(sessionId) {
    return this.getDiceBearGlyphUrl(sessionId);
  },

  // Deterministic 5x5 SVG identicon (100% offline fallback)
  getIdenticonSvgDataUri(sessionId, size = 48) {
    const hash = this.md5(String(sessionId || "session"));
    const hue = parseInt(hash.substring(0, 3), 16) % 360;
    const color = `hsl(${hue}, 65%, 48%)`;
    const bg = `hsl(${hue}, 35%, 96%)`;

    const cells = [];
    const cellSize = Math.round(size / 6);
    const offset = Math.round(size / 12);

    for (let col = 0; col < 3; col++) {
      for (let row = 0; row < 5; row++) {
        const charIdx = col * 5 + row + 3;
        const charVal = parseInt(hash.charAt(charIdx % hash.length), 16);
        if (charVal % 2 === 0) {
          cells.push({
            x: col * cellSize + offset,
            y: row * cellSize + offset,
            size: cellSize,
          });
          if (col < 2) {
            cells.push({
              x: (4 - col) * cellSize + offset,
              y: row * cellSize + offset,
              size: cellSize,
            });
          }
        }
      }
    }

    const rects = cells
      .map(
        (c) =>
          `<rect x="${c.x}" y="${c.y}" width="${c.size}" height="${c.size}" fill="${color}" />`,
      )
      .join("");
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}"><rect width="${size}" height="${size}" rx="${Math.round(size / 6)}" fill="${bg}"/>${rects}</svg>`;
    return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
  },

  // Deterministic avatar object for components
  getSessionAvatarSvg(sessionId, size = 18) {
    const url = this.getDiceBearGlyphUrl(sessionId);
    const fallback = this.getIdenticonSvgDataUri(sessionId, 48);
    return {
      url,
      fallback,
      bg: "#f8fafc",
      border: "#e2e8f0",
    };
  },

  // Country Flags (powered by flag-icons & vector SVG)
  getCountryFlag(countryCode, { size = 16, className = "" } = {}) {
    if (
      !countryCode ||
      countryCode === "UN" ||
      countryCode === "Unknown" ||
      countryCode === "XX"
    ) {
      return `<span class="country-flag-unknown ${className}" title="Unknown">🌐</span>`;
    }
    const code = String(countryCode).toLowerCase().trim();
    // The code ends up in a URL and in markup: only ISO 3166 alpha-2 codes are used.
    if (!/^[a-z]{2}$/.test(code)) {
      return `<span class="country-flag-unknown ${className}" title="Unknown">🌐</span>`;
    }
    const width = Math.round(size * 1.33);
    return `<span class="country-flag-wrap ${className}" title="${code.toUpperCase()}"><img src="https://flagcdn.com/${code}.svg" alt="${code.toUpperCase()}" class="country-flag-img" width="${width}" height="${size}" loading="lazy" onerror="this.onerror=null; this.outerHTML='<span class=\\'fi fi-${code}\\'></span>';" /></span>`;
  },

  // Rich Vector Brand Icons for Browsers (via jsdelivr browser-icon@1.0.2 with SVG fallback)
  getBrowserIcon(browser, size = 14, className = "") {
    const b = (browser || "").toLowerCase().trim();
    let slug = null;

    if (b.includes("operagx") || b.includes("opera gx")) {
      slug = "operagx";
    } else if (b.includes("opera") || b.includes("opr")) {
      slug = "opera";
    } else if (b.includes("edge") || b.includes("edg")) {
      slug = "edge";
    } else if (b.includes("brave")) {
      slug = "brave";
    } else if (b.includes("samsung")) {
      slug = "samsunginternet";
    } else if (b.includes("vivaldi")) {
      slug = "vivaldi";
    } else if (b.includes("tor")) {
      slug = "tor";
    } else if (b.includes("duckduckgo")) {
      slug = "duckduckgo";
    } else if (b.includes("yandex")) {
      slug = "yandex";
    } else if (
      b.includes("arc") ||
      b.includes("whale") ||
      b.includes("miui") ||
      b.includes("qq browser") ||
      b.includes("android browser")
    ) {
      // These browsers use Chromium and do not all publish a stable icon in
      // browser-icon, so show the recognisable Chromium family mark.
      slug = "chrome";
    } else if (b.includes("chromium")) {
      slug = "chromium";
    } else if (b.includes("chrome") || b.includes("crios")) {
      slug = "chrome";
    } else if (b.includes("firefox") || b.includes("fxios")) {
      slug = "firefox";
    } else if (
      b.includes("waterfox") ||
      b.includes("palemoon") ||
      b.includes("seamonkey")
    ) {
      slug = "firefox";
    } else if (b.includes("safari")) {
      slug = "safari";
    } else if (
      b === "ie" ||
      b.includes("internet explorer") ||
      b.includes("msie") ||
      b.includes("trident")
    ) {
      slug = "internetexplorer";
    } else if (
      b.includes("ucbrowser") ||
      b.includes("uc browser") ||
      b === "uc"
    ) {
      slug = "ucbrowser";
    } else if (b.includes("huawei")) {
      slug = "huawei";
    } else if (b.includes("maxthon")) {
      slug = "maxthon";
    } else if (b.includes("falkon")) {
      slug = "falkon";
    } else if (b.includes("midori")) {
      slug = "midori";
    } else if (b.includes("electron")) {
      slug = "electron";
    } else if (b.includes("qutebrowser")) {
      slug = "qutebrowser";
    }

    const fallback = `<svg viewBox=\'0 0 24 24\' width=\'${size}\' height=\'${size}\' fill=\'none\' stroke=\'#64748b\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' class=\'brand-icon-svg\' style=\'vertical-align: middle;\'><rect width=\'20\' height=\'16\' x=\'2\' y=\'4\' rx=\'2\'/><path d=\'M2 9h20M6 6.5h.01M9 6.5h.01M12 6.5h.01\'/></svg>`;

    if (!slug) {
      return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="brand-icon-svg" style="vertical-align: middle;"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M2 9h20M6 6.5h.01M9 6.5h.01M12 6.5h.01"/></svg>`;
    }

    const safeBrowser = String(browser || slug).replace(/"/g, "&quot;");
    return `<img src="https://cdn.jsdelivr.net/npm/browser-icon@1.0.2/icon/${slug}.svg" alt="${safeBrowser}" width="${size}" height="${size}" class="brand-icon-svg ${className}" style="vertical-align: middle; display: inline-block; object-fit: contain;" loading="lazy" onerror="this.onerror=null; this.outerHTML='${fallback}';" />`;
  },

  // Rich Vector Brand Icons for Operating Systems (via simple-icons on jsDelivr with SVG fallback)
  getOsIcon(os, size = 14, className = "") {
    const o = (os || "").toLowerCase().trim();
    let slug = null;

    if (o.includes("chrome os") || o.includes("chromeos") || o.includes("cros")) {
      slug = "chromeos";
    } else if (o.includes("windows phone")) {
      slug = "windows";
    } else if (o.includes("android")) {
      slug = "android";
    } else if (
      o.includes("ios") ||
      o.includes("iphone") ||
      o.includes("ipad") ||
      o.includes("tvos") ||
      o.includes("watchos")
    ) {
      slug = "apple";
    } else if (
      o.includes("mac") ||
      o.includes("macos") ||
      o.includes("darwin") ||
      o.includes("osx")
    ) {
      slug = "apple";
    } else if (o.includes("windows") || o.includes("win")) {
      slug = "windows";
    } else if (o.includes("harmony")) {
      slug = "huawei";
    } else if (o.includes("fire os")) {
      slug = "amazon";
    } else if (o.includes("kaios")) {
      slug = "kaios";
    } else if (o.includes("kali")) {
      slug = "kalilinux";
    } else if (o.includes("ubuntu")) {
      slug = "ubuntu";
    } else if (o.includes("mint")) {
      slug = "linuxmint";
    } else if (o.includes("fedora")) {
      slug = "fedora";
    } else if (o.includes("debian")) {
      slug = "debian";
    } else if (o.includes("arch")) {
      slug = "archlinux";
    } else if (o.includes("opensuse") || o.includes("suse")) {
      slug = "opensuse";
    } else if (o.includes("centos")) {
      slug = "centos";
    } else if (o.includes("raspbian") || o.includes("raspberry")) {
      slug = "raspberrypi";
    } else if (o.includes("freebsd")) {
      slug = "freebsd";
    } else if (o.includes("openbsd")) {
      slug = "openbsd";
    } else if (o.includes("solaris")) {
      slug = "solaris";
    } else if (o.includes("haiku")) {
      slug = "haiku";
    } else if (o.includes("tizen")) {
      slug = "tizen";
    } else if (o.includes("linux")) {
      slug = "linux";
    }

    const fallback = `<svg viewBox=\'0 0 24 24\' width=\'${size}\' height=\'${size}\' fill=\'none\' stroke=\'#64748b\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' class=\'brand-icon-svg\' style=\'vertical-align: middle;\'><rect width=\'18\' height=\'18\' x=\'3\' y=\'3\' rx=\'2\'/><path d=\'M9 3v18M3 9h18\'/></svg>`;

    if (!slug) {
      return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="brand-icon-svg" style="vertical-align: middle;"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18M3 9h18"/></svg>`;
    }

    const safeOs = String(os || slug).replace(/"/g, "&quot;");
    return `<img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/${slug}.svg" alt="${safeOs}" width="${size}" height="${size}" class="brand-icon-svg ${className}" style="vertical-align: middle; display: inline-block; object-fit: contain; opacity: 0.75;" loading="lazy" onerror="this.onerror=null; this.outerHTML='${fallback}';" />`;
  },

  // Devices
  getDeviceIcon(device, size = 14) {
    const d = (device || "").toLowerCase();
    if (d === "mobile") return this.get("smartphone", { size });
    if (d === "tablet") return this.get("tablet", { size });
    return this.get("monitor", { size });
  },
};

window.Icons = Icons;
