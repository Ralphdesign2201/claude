import { Router } from "express";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";
import { ApiError } from "../utils/ApiError";
import { upload } from "../lib/upload";

const router = Router();

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { clientId, projectId } = req.query as Record<string, string>;
    const where: any = {};
    if (clientId) where.clientId = clientId;
    if (projectId) where.projectId = projectId;

    const documents = await prisma.document.findMany({ where, orderBy: { createdAt: "desc" } });
    res.json(documents);
  })
);

router.post(
  "/",
  upload.single("file"),
  asyncHandler(async (req, res) => {
    if (!req.file) throw ApiError.badRequest("Keine Datei hochgeladen");
    const { clientId, projectId } = req.body as { clientId?: string; projectId?: string };

    const document = await prisma.document.create({
      data: {
        clientId: clientId || undefined,
        projectId: projectId || undefined,
        name: req.file.originalname,
        url: `/uploads/${req.file.filename}`,
        mimeType: req.file.mimetype,
        size: req.file.size,
      },
    });
    res.status(201).json(document);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.document.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

export default router;
