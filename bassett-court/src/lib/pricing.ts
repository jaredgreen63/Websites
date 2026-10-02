import type { PriceRounding } from '~/site.config';

/**
 * Apply the configured markup to an upstream price, plus any flat surcharge.
 *
 * Returns null when there is nothing to mark up — a vehicle listed without a
 * price stays without a price rather than acquiring a fabricated one, and a
 * surcharge never conjures one either.
 *
 * The surcharge lands after the percentage and before rounding, so the
 * published figure is rounded once, as a whole.
 */
export function applyMarkup(
  sourcePrice: number | null | undefined,
  rate: number,
  rounding: PriceRounding = 'nearest-25',
  surcharge = 0,
): number | null {
  if (sourcePrice == null || !Number.isFinite(sourcePrice) || sourcePrice <= 0) {
    return null;
  }
  const adjusted = sourcePrice * (1 + rate) + (Number.isFinite(surcharge) ? surcharge : 0);
  return roundPrice(adjusted, rounding);
}

/**
 * The flat amount a vehicle's condition earns it, if any.
 *
 * Only `new` qualifies. `certified` is a used vehicle with a warranty on it,
 * so it is priced as used, and anything the feed leaves unclassified is left
 * alone rather than assumed new.
 */
export function conditionSurcharge(
  condition: string | null | undefined,
  amount: number,
): number {
  if (!Number.isFinite(amount) || amount <= 0) return 0;
  return condition === 'new' ? amount : 0;
}

export function roundPrice(value: number, mode: PriceRounding): number {
  switch (mode) {
    case 'none':
      return Math.round(value);
    case 'nearest-5':
      return Math.round(value / 5) * 5;
    case 'nearest-25':
      return Math.round(value / 25) * 25;
    case 'nearest-100':
      return Math.round(value / 100) * 100;
    case 'dealer-95': {
      // Snap up to the next "x95" ending on the hundreds boundary.
      const hundreds = Math.floor(value / 100) * 100;
      const candidate = hundreds + 95;
      return candidate >= value ? candidate : candidate + 100;
    }
    default:
      return Math.round(value);
  }
}

export function formatPrice(
  value: number | null | undefined,
  fallback = 'Call for Price',
): string {
  if (value == null || !Number.isFinite(value) || value <= 0) return fallback;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
  }).format(value);
}

export function formatMileage(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(value)) return '—';
  if (value <= 0) return '0 mi';
  return `${new Intl.NumberFormat('en-US').format(Math.round(value))} mi`;
}

/** Standard amortized monthly payment. Returns null for degenerate inputs. */
export function monthlyPayment(
  principal: number,
  annualRatePct: number,
  termMonths: number,
): number | null {
  if (!Number.isFinite(principal) || principal <= 0) return null;
  if (!Number.isFinite(termMonths) || termMonths <= 0) return null;
  const monthlyRate = annualRatePct / 100 / 12;
  if (monthlyRate <= 0) return principal / termMonths;
  const growth = Math.pow(1 + monthlyRate, termMonths);
  return (principal * monthlyRate * growth) / (growth - 1);
}
