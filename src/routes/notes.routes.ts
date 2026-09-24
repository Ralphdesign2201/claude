import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";

const router = Router();

const noteSchema = z.object({
  clientId: z.string().optional(),
  projectId: z.string().optional(),
  body: z.string().min(1),
  pinned: z.boolean().optional(),
});

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { clientId, projectId } = req.query as Record<string, string>;
    const where: any = {};
    if (clientId) where.clientId = clientId;
    if (projectId) where.projectId = projectId;

    const notes = await prisma.note.findMany({
      where,
      orderBy: [{ pinned: "desc" }, { createdAt: "desc" }],
      include: { author: { select: { id: true, name: true } } },
    });
    res.json(notes);
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const data = noteSchema.parse(req.body);
    const note = await prisma.note.create({ data: { ...data, authorId: req.user?.id } });
    res.status(201).json(note);
  })
);

router.patch(
  "/:id",
  asyncHandler(async (req, res) => {
    const data = noteSchema.partial().parse(req.body);
    const note = await prisma.note.update({ where: { id: req.params.id }, data });
    res.json(note);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.note.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

export default router;
