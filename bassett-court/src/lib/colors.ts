/**
 * Exterior colour families.
 *
 * Manufacturers ship names, not colours: a single lot carries "Summit White",
 * "Iridescent Pearl Tricoat", "Cajun Red Tintcoat" and "Area 51". Nobody
 * shops for those. They shop for white, or red. This collapses the marketing
 * names into families people actually filter by, and hands back a swatch
 * colour so the same answer drives the filter chips and the placeholder
 * silhouettes.
 */

export interface ColorFamily {
  /** Stable key used in URLs and filter state. */
  id: string;
  label: string;
  /** Swatch colour. */
  hex: string;
}

export const COLOR_FAMILIES: ColorFamily[] = [
  { id: 'black', label: 'Black', hex: '#23272c' },
  { id: 'white', label: 'White', hex: '#e8e9ea' },
  { id: 'gray', label: 'Gray', hex: '#7b828b' },
  { id: 'silver', label: 'Silver', hex: '#b9bfc5' },
  { id: 'red', label: 'Red', hex: '#a4322f' },
  { id: 'blue', label: 'Blue', hex: '#2f5a86' },
  { id: 'green', label: 'Green', hex: '#4a6b52' },
  { id: 'beige', label: 'Beige', hex: '#c2ab88' },
  { id: 'brown', label: 'Brown', hex: '#7a5a41' },
  { id: 'gold', label: 'Gold', hex: '#b99537' },
  { id: 'orange', label: 'Orange', hex: '#b86a30' },
  { id: 'yellow', label: 'Yellow', hex: '#cbb03a' },
  { id: 'purple', label: 'Purple', hex: '#5a4570' },
  { id: 'other', label: 'Other', hex: '#6d7885' },
];

const BY_ID = new Map(COLOR_FAMILIES.map((family) => [family.id, family]));

/**
 * Ordered matchers — first hit wins, so order carries meaning.
 *
 * Specific hues come first because the vague words attach to everything:
 * "Pearl Beige Metallic" is beige, "Inferno Red Tinted Pearl" is red, and
 * "Black Metallic" is black. Matching "pearl" or "metallic" early would
 * swallow all three.
 */
const MATCHERS: [string, RegExp][] = [
  ['red', /\b(red|crimson|garnet|cherry|scarlet|maroon|burgundy|ruby|cajun|inferno|torch|radiant)\b/],
  ['blue', /\b(blue|navy|cobalt|azure|riptide|lakeshore|indigo|teal|cyan|sapphire)\b/],
  ['green', /\b(green|cypress|cacti|forest|sage|olive|emerald|lime|mint)\b/],
  ['purple', /\b(purple|violet|plum|amethyst|lavender)\b/],
  ['orange', /\b(orange|copper|rust|tangerine|lava)\b/],
  ['yellow', /\b(yellow|lemon|citrus)\b/],
  ['gold', /\b(gold|champagne)\b/],
  ['brown', /\b(brown|bronze|mocha|espresso|chestnut|walnut|chocolate|coffee)\b/],
  ['beige', /\b(beige|tan|sandstone|sand|khaki|cream|ivory|taupe|almond|dune|desert)\b/],
  ['black', /\b(black|onyx|obsidian|midnight|ebony|noir|jet)\b/],
  // White and gray come before silver on purpose. "Platinum White Pearl" is a
  // white car and "Platinum Gray Metallic" is a grey one; letting the metal
  // word match first would file both under silver.
  ['white', /\b(white|frost|arctic|glacier|snow|polar|alabaster|summit|powder)\b/],
  ['gray', /\b(gray|grey|graphite|slate|charcoal|gunmetal|gun|steel|ash|magnetic|meteorite|smoke|shadow|granite|iron|carbon|sterling)\b/],
  ['silver', /\b(silver|aluminum|aluminium|chrome|platinum|titanium|nickel)\b/],
  // Only once every real colour word has been ruled out: a bare "Pearl",
  // "Crystal" or "Iridescent" with nothing else attached reads as white.
  ['white', /\b(pearl|crystal|iridescent|opal)\b/],
];

/** Which family a manufacturer colour name belongs to. */
export function colorFamilyId(name: string | null | undefined): string {
  if (!name) return 'other';
  const needle = name.toLowerCase();
  for (const [id, pattern] of MATCHERS) {
    if (pattern.test(needle)) return id;
  }
  return 'other';
}

export function colorFamily(name: string | null | undefined): ColorFamily {
  return BY_ID.get(colorFamilyId(name)) ?? BY_ID.get('other')!;
}

/** Swatch colour for a manufacturer colour name. */
export function swatchFor(name: string | null | undefined): string {
  return colorFamily(name).hex;
}

export function familyLabel(id: string): string {
  return BY_ID.get(id)?.label ?? id;
}
