import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { COLOR_FAMILIES, colorFamilyId, familyLabel, swatchFor } from '../src/lib/colors';
import { buildFacets } from '../src/lib/inventory';
import { normalizeVehicle } from '../src/lib/normalize';
import type { Vehicle } from '../src/lib/types';

const NOW = '2026-09-30T12:00:00.000Z';
function vehicle(color: string | null, vin: string): Vehicle {
  const v = normalizeVehicle(
    { vin, make: 'Chevrolet', model: 'Tahoe', year: 2026, price: 60_000, exteriorColor: color },
    NOW,
  );
  assert.ok(v);
  return v;
}

describe('colorFamilyId', () => {
  it('reads plain colour names', () => {
    assert.equal(colorFamilyId('Black'), 'black');
    assert.equal(colorFamilyId('Summit White'), 'white');
    assert.equal(colorFamilyId('Sterling Gray Metallic'), 'gray');
    assert.equal(colorFamilyId('Lakeshore Blue'), 'blue');
    assert.equal(colorFamilyId('Silver Ice Metallic'), 'silver');
  });

  it('lets an explicit colour beat a metal word', () => {
    // "Platinum" reads as silver on its own, but these are a white car and a
    // grey one. Matching the metal first would file both under silver.
    assert.equal(colorFamilyId('Platinum White Pearl'), 'white');
    assert.equal(colorFamilyId('Platinum White'), 'white');
    assert.equal(colorFamilyId('Platinum Gray Metallic'), 'gray');
    assert.equal(colorFamilyId('Billet Silver Metallic'), 'silver');
  });

  it('treats "pearl" as white only when nothing else claims the name', () => {
    assert.equal(colorFamilyId('Pearl'), 'white');
    assert.equal(colorFamilyId('Iridescent Pearl Tricoat'), 'white');
    assert.equal(colorFamilyId('Black Pearl'), 'black');
    assert.equal(colorFamilyId('Inferno Red Tinted Pearl'), 'red');
    assert.equal(colorFamilyId('Pearl Beige Metallic'), 'beige');
    // Honda's "Molten Lava Pearl" is orange; the pearl fallback must not win.
    assert.equal(colorFamilyId('Molten Lava Pearl'), 'orange');
  });

  it('handles the poetic names manufacturers actually ship', () => {
    assert.equal(colorFamilyId('Cajun Red Tintcoat'), 'red');
    assert.equal(colorFamilyId('Radiant Red'), 'red');
    assert.equal(colorFamilyId('Midnight Black Metallic'), 'black');
    assert.equal(colorFamilyId('Fresh Powder'), 'white');
    assert.equal(colorFamilyId('Sandstone'), 'beige');
    assert.equal(colorFamilyId('Sterling Metallic'), 'gray');
    assert.equal(colorFamilyId('Cypress'), 'green');
  });

  it('falls back to "other" rather than guessing', () => {
    // Jeep's "Area 51" is a grey-green nobody would name from the words.
    assert.equal(colorFamilyId('Area 51'), 'other');
    assert.equal(colorFamilyId(null), 'other');
    assert.equal(colorFamilyId(''), 'other');
  });

  it('is case-insensitive', () => {
    assert.equal(colorFamilyId('SUMMIT WHITE'), 'white');
    assert.equal(colorFamilyId('summit white'), 'white');
  });

  it('gives every family a distinct swatch', () => {
    const hexes = COLOR_FAMILIES.map((f) => f.hex);
    assert.equal(new Set(hexes).size, hexes.length, 'two families share a swatch colour');
    for (const hex of hexes) assert.match(hex, /^#[0-9a-f]{6}$/i);
  });

  it('resolves a swatch for any input', () => {
    assert.equal(swatchFor('Summit White'), COLOR_FAMILIES.find((f) => f.id === 'white')!.hex);
    assert.equal(swatchFor(null), COLOR_FAMILIES.find((f) => f.id === 'other')!.hex);
  });

  it('labels every family', () => {
    for (const family of COLOR_FAMILIES) assert.equal(familyLabel(family.id), family.label);
  });
});

describe('colour facet', () => {
  const vehicles = [
    vehicle('Black', '1GNSKPKD5RR100001'),
    vehicle('Black Metallic', '1GNSKPKD5RR100002'),
    vehicle('Summit White', '1GNSKPKD5RR100003'),
    vehicle('Radiant Red', '1GNSKPKD5RR100004'),
    vehicle('Area 51', '1GNSKPKD5RR100005'),
    vehicle(null, '1GNSKPKD5RR100006'),
  ];

  it('collapses manufacturer names into families with counts', () => {
    const { colors } = buildFacets(vehicles);
    const byId = Object.fromEntries(colors.map((c) => [c.id, c.count]));
    assert.equal(byId.black, 2, 'Black and Black Metallic are one family');
    assert.equal(byId.white, 1);
    assert.equal(byId.red, 1);
    assert.equal(byId.other, 2, 'unrecognised and missing colours both land in other');
  });

  it('omits families with no vehicles', () => {
    const { colors } = buildFacets(vehicles);
    assert.ok(!colors.some((c) => c.id === 'purple'));
    assert.ok(colors.every((c) => c.count > 0));
  });

  it('keeps palette order rather than sorting by count', () => {
    // Swatches that rearrange between visits are worse than ones that do not.
    const { colors } = buildFacets(vehicles);
    const ids = colors.map((c) => c.id);
    const expected = COLOR_FAMILIES.filter((f) => ids.includes(f.id)).map((f) => f.id);
    assert.deepEqual(ids, expected);
  });
});
