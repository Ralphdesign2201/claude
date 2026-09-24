export function computeInvoiceTotals(
  items: { quantity: number; unitPrice: number }[],
  taxRate: number,
  discount: number
) {
  const subtotal = items.reduce((sum, i) => sum + i.quantity * i.unitPrice, 0);
  const discounted = subtotal - discount;
  const tax = discounted * (taxRate / 100);
  const total = discounted + tax;
  return { subtotal, tax, total: Math.max(0, total) };
}

export async function nextInvoiceNumber(countThisYear: number) {
  const year = new Date().getFullYear();
  const seq = String(countThisYear + 1).padStart(4, "0");
  return `RE-${year}-${seq}`;
}
