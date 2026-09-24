import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";
import { ApiError } from "../utils/ApiError";

const router = Router();

const contractSchema = z.object({
  clientId: z.string(),
  title: z.string().min(1),
  status: z.enum(["DRAFT", "SENT", "SIGNED", "CANCELLED"]).optional(),
  value: z.number().optional(),
  startDate: z.string().datetime().optional().or(z.literal("")),
  endDate: z.string().datetime().optional().or(z.literal("")),
  signedAt: z.string().datetime().optional().or(z.literal("")),
  fileUrl: z.string().optional(),
});

function normalizeDates(data: any) {
  const out = { ...data };
  for (const key of ["startDate", "endDate", "signedAt"]) {
    if (out[key] === "") out[key] = undefined;
    else if (out[key]) out[key] = new Date(out[key]);
  }
  return out;
}

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { clientId, status } = req.query as Record<string, string>;
    const where: any = {};
    if (clientId) where.clientId = clientId;
    if (status) where.status = status;

    const contracts = await prisma.contract.findMany({
      where,
      orderBy: { createdAt: "desc" },
      include: { client: { select: { id: true, name: true } } },
    });
    res.json(contracts);
  })
);

router.get(
  "/:id",
  asyncHandler(async (req, res) => {
    const contract = await prisma.contract.findUnique({
      where: { id: req.params.id },
      include: { client: true },
    });
    if (!contract) throw ApiError.notFound("Vertrag nicht gefunden");
    res.json(contract);
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const data = normalizeDates(contractSchema.parse(req.body));
    const contract = await prisma.contract.create({ data });
    res.status(201).json(contract);
  })
);

router.patch(
  "/:id",
  asyncHandler(async (req, res) => {
    const data = normalizeDates(contractSchema.partial().parse(req.body));
    const contract = await prisma.contract.update({ where: { id: req.params.id }, data });
    res.json(contract);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.contract.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

export default router;
