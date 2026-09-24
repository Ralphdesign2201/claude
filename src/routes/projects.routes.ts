import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";
import { ApiError } from "../utils/ApiError";
import { parsePagination, paginated } from "../utils/pagination";
import { logActivity } from "../utils/activity";

const router = Router();

const projectSchema = z.object({
  clientId: z.string(),
  name: z.string().min(1),
  description: z.string().optional(),
  status: z.enum(["PLANNED", "IN_PROGRESS", "REVIEW", "DONE", "ON_HOLD", "CANCELLED"]).optional(),
  budget: z.number().optional(),
  hourlyRate: z.number().optional(),
  startDate: z.string().datetime().optional().or(z.literal("")),
  dueDate: z.string().datetime().optional().or(z.literal("")),
  ownerId: z.string().optional(),
});

function normalizeDates<T extends Record<string, any>>(data: T) {
  const out: any = { ...data };
  for (const key of ["startDate", "dueDate"]) {
    if (out[key] === "") out[key] = undefined;
    else if (out[key]) out[key] = new Date(out[key]);
  }
  return out;
}

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { page, pageSize, skip, take } = parsePagination(req);
    const { search, status, clientId, ownerId } = req.query as Record<string, string>;

    const where: any = {};
    if (status) where.status = status;
    if (clientId) where.clientId = clientId;
    if (ownerId) where.ownerId = ownerId;
    if (search) where.name = { contains: search };

    const [items, total] = await Promise.all([
      prisma.project.findMany({
        where,
        skip,
        take,
        orderBy: { createdAt: "desc" },
        include: {
          client: { select: { id: true, name: true, company: true } },
          owner: { select: { id: true, name: true } },
          _count: { select: { tasks: true, invoices: true } },
        },
      }),
      prisma.project.count({ where }),
    ]);

    res.json(paginated(items, total, page, pageSize));
  })
);

router.get(
  "/:id",
  asyncHandler(async (req, res) => {
    const project = await prisma.project.findUnique({
      where: { id: req.params.id },
      include: {
        client: true,
        owner: { select: { id: true, name: true, email: true } },
        tasks: { orderBy: [{ position: "asc" }, { createdAt: "asc" }] },
        timeEntries: { orderBy: { date: "desc" } },
        invoices: { orderBy: { createdAt: "desc" } },
        documents: { orderBy: { createdAt: "desc" } },
        notes: { orderBy: { createdAt: "desc" } },
      },
    });
    if (!project) throw ApiError.notFound("Projekt nicht gefunden");
    res.json(project);
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const data = normalizeDates(projectSchema.parse(req.body));
    const project = await prisma.project.create({
      data: { ...data, ownerId: data.ownerId || req.user?.id },
    });
    await logActivity({
      clientId: project.clientId,
      projectId: project.id,
      userId: req.user?.id,
      type: "PROJECT_CREATED",
      message: `Projekt "${project.name}" wurde angelegt`,
    });
    res.status(201).json(project);
  })
);

router.patch(
  "/:id",
  asyncHandler(async (req, res) => {
    const data = normalizeDates(projectSchema.partial().parse(req.body));
    const project = await prisma.project.update({ where: { id: req.params.id }, data });
    await logActivity({
      clientId: project.clientId,
      projectId: project.id,
      userId: req.user?.id,
      type: "PROJECT_UPDATED",
      message: `Projekt "${project.name}" wurde aktualisiert`,
    });
    res.json(project);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.project.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

// Tasks nested under projects
const taskSchema = z.object({
  title: z.string().min(1),
  description: z.string().optional(),
  status: z.enum(["OPEN", "IN_PROGRESS", "DONE"]).optional(),
  priority: z.enum(["LOW", "MEDIUM", "HIGH", "URGENT"]).optional(),
  dueDate: z.string().datetime().optional().or(z.literal("")),
  position: z.number().optional(),
  assigneeId: z.string().optional(),
});

router.get(
  "/:id/tasks",
  asyncHandler(async (req, res) => {
    const tasks = await prisma.task.findMany({
      where: { projectId: req.params.id },
      orderBy: [{ position: "asc" }, { createdAt: "asc" }],
      include: { assignee: { select: { id: true, name: true } } },
    });
    res.json(tasks);
  })
);

router.post(
  "/:id/tasks",
  asyncHandler(async (req, res) => {
    const data = normalizeDates(taskSchema.parse(req.body));
    const task = await prisma.task.create({ data: { ...data, projectId: req.params.id } });
    res.status(201).json(task);
  })
);

export default router;
