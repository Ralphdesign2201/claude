import { Router } from "express";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";
import { computeInvoiceTotals } from "../utils/invoiceMath";

const router = Router();

router.get(
  "/summary",
  asyncHandler(async (_req, res) => {
    const [clientsTotal, clientsByStatus, projectsActive, tasksOpen, invoices, upcomingTasks, recentActivities] =
      await Promise.all([
        prisma.client.count(),
        prisma.client.groupBy({ by: ["status"], _count: true }),
        prisma.project.count({ where: { status: { in: ["PLANNED", "IN_PROGRESS", "REVIEW"] } } }),
        prisma.task.count({ where: { status: { not: "DONE" } } }),
        prisma.invoice.findMany({ include: { items: true, payments: true } }),
        prisma.task.findMany({
          where: { status: { not: "DONE" }, dueDate: { not: null } },
          orderBy: { dueDate: "asc" },
          take: 8,
          include: { project: { select: { id: true, name: true, clientId: true } } },
        }),
        prisma.activity.findMany({
          orderBy: { createdAt: "desc" },
          take: 15,
          include: {
            client: { select: { id: true, name: true } },
            project: { select: { id: true, name: true } },
            user: { select: { id: true, name: true } },
          },
        }),
      ]);

    let revenuePaid = 0;
    let outstanding = 0;
    let overdue = 0;
    const now = new Date();

    for (const inv of invoices) {
      const totals = computeInvoiceTotals(inv.items, inv.taxRate, inv.discount);
      const paid = inv.payments.reduce((s, p) => s + p.amount, 0);
      revenuePaid += paid;
      const balance = totals.total - paid;
      if (balance > 0 && inv.status !== "CANCELLED") {
        outstanding += balance;
        if (inv.dueDate && inv.dueDate < now) overdue += balance;
      }
    }

    res.json({
      clients: {
        total: clientsTotal,
        byStatus: Object.fromEntries(clientsByStatus.map((c) => [c.status, c._count])),
      },
      projects: { active: projectsActive },
      tasks: { open: tasksOpen, upcoming: upcomingTasks },
      revenue: { paid: revenuePaid, outstanding, overdue },
      activities: recentActivities,
    });
  })
);

export default router;
