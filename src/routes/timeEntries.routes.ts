import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";

const router = Router();

const timeEntrySchema = z.object({
  projectId: z.string(),
  taskId: z.string().optional(),
  description: z.string().optional(),
  minutes: z.number().int().positive(),
  billable: z.boolean().optional(),
  date: z.string().datetime().optional(),
});

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { projectId, userId, from, to } = req.query as Record<string, string>;
    const where: any = {};
    if (projectId) where.projectId = projectId;
    if (userId) where.userId = userId;
    if (from || to) {
      where.date = {};
      if (from) where.date.gte = new Date(from);
      if (to) where.date.lte = new Date(to);
    }

    const entries = await prisma.timeEntry.findMany({
      where,
      orderBy: { date: "desc" },
      include: {
        project: { select: { id: true, name: true, clientId: true, hourlyRate: true } },
        task: { select: { id: true, title: true } },
        user: { select: { id: true, name: true } },
      },
    });
    res.json(entries);
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const data = timeEntrySchema.parse(req.body);
    const entry = await prisma.timeEntry.create({
      data: {
        ...data,
        date: data.date ? new Date(data.date) : undefined,
        userId: req.user?.id,
      },
    });
    res.status(201).json(entry);
  })
);

router.patch(
  "/:id",
  asyncHandler(async (req, res) => {
    const data = timeEntrySchema.partial().parse(req.body);
    const entry = await prisma.timeEntry.update({
      where: { id: req.params.id },
      data: { ...data, date: data.date ? new Date(data.date) : undefined },
    });
    res.json(entry);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.timeEntry.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

export default router;
