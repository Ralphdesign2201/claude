import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";
import { ApiError } from "../utils/ApiError";
import { parsePagination, paginated } from "../utils/pagination";
import { computeInvoiceTotals, nextInvoiceNumber } from "../utils/invoiceMath";
import { logActivity } from "../utils/activity";

const router = Router();

const itemSchema = z.object({
  description: z.string().min(1),
  quantity: z.number().positive().default(1),
  unitPrice: z.number(),
  position: z.number().optional(),
});

const invoiceSchema = z.object({
  clientId: z.string(),
  projectId: z.string().optional(),
  status: z.enum(["DRAFT", "SENT", "PAID", "OVERDUE", "CANCELLED"]).optional(),
  issueDate: z.string().datetime().optional(),
  dueDate: z.string().datetime().optional().or(z.literal("")),
  taxRate: z.number().optional(),
  discount: z.number().optional(),
  notes: z.string().optional(),
  currency: z.string().optional(),
  items: z.array(itemSchema).min(1),
});

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { page, pageSize, skip, take } = parsePagination(req);
    const { status, clientId, search } = req.query as Record<string, string>;

    const where: any = {};
    if (status) where.status = status;
    if (clientId) where.clientId = clientId;
    if (search) where.number = { contains: search };

    const [items, total] = await Promise.all([
      prisma.invoice.findMany({
        where,
        skip,
        take,
        orderBy: { createdAt: "desc" },
        include: {
          client: { select: { id: true, name: true, company: true } },
          items: true,
          payments: true,
        },
      }),
      prisma.invoice.count({ where }),
    ]);

    const withTotals = items.map((inv) => {
      const totals = computeInvoiceTotals(inv.items, inv.taxRate, inv.discount);
      const paid = inv.payments.reduce((s, p) => s + p.amount, 0);
      return { ...inv, totals: { ...totals, paid, balance: totals.total - paid } };
    });

    res.json(paginated(withTotals, total, page, pageSize));
  })
);

router.get(
  "/:id",
  asyncHandler(async (req, res) => {
    const invoice = await prisma.invoice.findUnique({
      where: { id: req.params.id },
      include: {
        client: true,
        project: { select: { id: true, name: true } },
        items: { orderBy: { position: "asc" } },
        payments: { orderBy: { paidAt: "desc" } },
      },
    });
    if (!invoice) throw ApiError.notFound("Rechnung nicht gefunden");
    const totals = computeInvoiceTotals(invoice.items, invoice.taxRate, invoice.discount);
    const paid = invoice.payments.reduce((s, p) => s + p.amount, 0);
    res.json({ ...invoice, totals: { ...totals, paid, balance: totals.total - paid } });
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const data = invoiceSchema.parse(req.body);
    const year = new Date().getFullYear();
    const countThisYear = await prisma.invoice.count({
      where: { number: { startsWith: `RE-${year}-` } },
    });
    const number = await nextInvoiceNumber(countThisYear);

    const invoice = await prisma.invoice.create({
      data: {
        number,
        clientId: data.clientId,
        projectId: data.projectId,
        status: data.status || "DRAFT",
        issueDate: data.issueDate ? new Date(data.issueDate) : undefined,
        dueDate: data.dueDate ? new Date(data.dueDate) : undefined,
        taxRate: data.taxRate ?? 19,
        discount: data.discount ?? 0,
        notes: data.notes,
        currency: data.currency ?? "EUR",
        items: {
          create: data.items.map((item, idx) => ({ ...item, position: item.position ?? idx })),
        },
      },
      include: { items: true, client: true },
    });

    await logActivity({
      clientId: invoice.clientId,
      projectId: invoice.projectId || undefined,
      userId: req.user?.id,
      type: "INVOICE_CREATED",
      message: `Rechnung ${invoice.number} wurde erstellt`,
    });

    res.status(201).json(invoice);
  })
);

router.patch(
  "/:id",
  asyncHandler(async (req, res) => {
    const data = invoiceSchema.partial().parse(req.body);

    const invoice = await prisma.$transaction(async (tx) => {
      if (data.items) {
        await tx.invoiceItem.deleteMany({ where: { invoiceId: req.params.id } });
      }
      return tx.invoice.update({
        where: { id: req.params.id },
        data: {
          clientId: data.clientId,
          projectId: data.projectId,
          status: data.status,
          issueDate: data.issueDate ? new Date(data.issueDate) : undefined,
          dueDate: data.dueDate ? new Date(data.dueDate) : undefined,
          taxRate: data.taxRate,
          discount: data.discount,
          notes: data.notes,
          currency: data.currency,
          paidAt: data.status === "PAID" ? new Date() : undefined,
          items: data.items
            ? { create: data.items.map((item, idx) => ({ ...item, position: item.position ?? idx })) }
            : undefined,
        },
        include: { items: true },
      });
    });

    res.json(invoice);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.invoice.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

// Payments
const paymentSchema = z.object({
  amount: z.number().positive(),
  method: z.string().optional(),
  paidAt: z.string().datetime().optional(),
  note: z.string().optional(),
});

router.post(
  "/:id/payments",
  asyncHandler(async (req, res) => {
    const data = paymentSchema.parse(req.body);
    const payment = await prisma.payment.create({
      data: {
        ...data,
        paidAt: data.paidAt ? new Date(data.paidAt) : undefined,
        invoiceId: req.params.id,
      },
    });

    const invoice = await prisma.invoice.findUnique({
      where: { id: req.params.id },
      include: { items: true, payments: true },
    });
    if (invoice) {
      const totals = computeInvoiceTotals(invoice.items, invoice.taxRate, invoice.discount);
      const paidTotal = invoice.payments.reduce((s, p) => s + p.amount, 0);
      if (paidTotal >= totals.total && invoice.status !== "PAID") {
        await prisma.invoice.update({
          where: { id: invoice.id },
          data: { status: "PAID", paidAt: new Date() },
        });
      }
    }

    res.status(201).json(payment);
  })
);

router.delete(
  "/:id/payments/:paymentId",
  asyncHandler(async (req, res) => {
    await prisma.payment.delete({ where: { id: req.params.paymentId } });
    res.status(204).send();
  })
);

export default router;
