import { Router } from "express";
import bcrypt from "bcryptjs";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";
import { requireAdmin } from "../middleware/auth";

const router = Router();

router.get(
  "/",
  asyncHandler(async (_req, res) => {
    const users = await prisma.user.findMany({
      select: { id: true, name: true, email: true, role: true, createdAt: true },
      orderBy: { createdAt: "asc" },
    });
    res.json(users);
  })
);

const updateSchema = z.object({
  name: z.string().min(2).optional(),
  role: z.enum(["ADMIN", "MEMBER"]).optional(),
  password: z.string().min(8).optional(),
});

router.patch(
  "/:id",
  requireAdmin,
  asyncHandler(async (req, res) => {
    const data = updateSchema.parse(req.body);
    const update: any = { name: data.name, role: data.role };
    if (data.password) update.passwordHash = await bcrypt.hash(data.password, 10);

    const user = await prisma.user.update({
      where: { id: req.params.id },
      data: update,
      select: { id: true, name: true, email: true, role: true },
    });
    res.json(user);
  })
);

router.delete(
  "/:id",
  requireAdmin,
  asyncHandler(async (req, res) => {
    await prisma.user.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

export default router;
