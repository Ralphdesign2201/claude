import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";

const router = Router();

const taskUpdateSchema = z.object({
  title: z.string().min(1).optional(),
  description: z.string().optional(),
  status: z.enum(["OPEN", "IN_PROGRESS", "DONE"]).optional(),
  priority: z.enum(["LOW", "MEDIUM", "HIGH", "URGENT"]).optional(),
  dueDate: z.string().datetime().optional().or(z.literal("")),
  position: z.number().optional(),
  assigneeId: z.string().optional(),
});

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { assigneeId, status, priority } = req.query as Record<string, string>;
    const where: any = {};
    if (assigneeId) where.assigneeId = assigneeId;
    if (status) where.status = status;
    if (priority) where.priority = priority;

    const tasks = await prisma.task.findMany({
      where,
      orderBy: [{ dueDate: "asc" }, { createdAt: "desc" }],
      include: {
        project: { select: { id: true, name: true, clientId: true } },
        assignee: { select: { id: true, name: true } },
      },
    });
    res.json(tasks);
  })
);

router.patch(
  "/:id",
  asyncHandler(async (req, res) => {
    const data: any = taskUpdateSchema.parse(req.body);
    if (data.dueDate === "") data.dueDate = undefined;
    else if (data.dueDate) data.dueDate = new Date(data.dueDate);

    const task = await prisma.task.update({ where: { id: req.params.id }, data });
    res.json(task);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.task.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

export default router;
