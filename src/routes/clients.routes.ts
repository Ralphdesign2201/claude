import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../utils/asyncHandler";
import { ApiError } from "../utils/ApiError";
import { parsePagination, paginated } from "../utils/pagination";
import { logActivity } from "../utils/activity";

const router = Router();

const clientSchema = z.object({
  name: z.string().min(1),
  company: z.string().optional(),
  email: z.string().email().optional().or(z.literal("")),
  phone: z.string().optional(),
  website: z.string().optional(),
  address: z.string().optional(),
  city: z.string().optional(),
  zip: z.string().optional(),
  country: z.string().optional(),
  vatId: z.string().optional(),
  status: z.enum(["LEAD", "ACTIVE", "INACTIVE", "ARCHIVED"]).optional(),
  source: z.string().optional(),
  tags: z.string().optional(),
  notesText: z.string().optional(),
  avatarUrl: z.string().optional(),
  ownerId: z.string().optional(),
});

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { page, pageSize, skip, take } = parsePagination(req);
    const { search, status, ownerId, tag, sort } = req.query as Record<string, string>;

    const where: any = {};
    if (status) where.status = status;
    if (ownerId) where.ownerId = ownerId;
    if (tag) where.tags = { contains: tag };
    if (search) {
      where.OR = [
        { name: { contains: search } },
        { company: { contains: search } },
        { email: { contains: search } },
        { phone: { contains: search } },
      ];
    }

    const orderBy = sort === "name" ? { name: "asc" as const } : { createdAt: "desc" as const };

    const [items, total] = await Promise.all([
      prisma.client.findMany({
        where,
        skip,
        take,
        orderBy,
        include: {
          owner: { select: { id: true, name: true } },
          _count: { select: { projects: true, invoices: true } },
        },
      }),
      prisma.client.count({ where }),
    ]);

    res.json(paginated(items, total, page, pageSize));
  })
);

router.get(
  "/:id",
  asyncHandler(async (req, res) => {
    const client = await prisma.client.findUnique({
      where: { id: req.params.id },
      include: {
        owner: { select: { id: true, name: true, email: true } },
        contacts: true,
        projects: { orderBy: { createdAt: "desc" } },
        invoices: { orderBy: { createdAt: "desc" } },
        contracts: { orderBy: { createdAt: "desc" } },
        notes: { orderBy: { createdAt: "desc" } },
        documents: { orderBy: { createdAt: "desc" } },
        activities: { orderBy: { createdAt: "desc" }, take: 30 },
      },
    });
    if (!client) throw ApiError.notFound("Kunde nicht gefunden");
    res.json(client);
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const data = clientSchema.parse(req.body);
    const client = await prisma.client.create({
      data: { ...data, email: data.email || undefined, ownerId: data.ownerId || req.user?.id },
    });
    await logActivity({
      clientId: client.id,
      userId: req.user?.id,
      type: "CLIENT_CREATED",
      message: `Kunde "${client.name}" wurde angelegt`,
    });
    res.status(201).json(client);
  })
);

router.patch(
  "/:id",
  asyncHandler(async (req, res) => {
    const data = clientSchema.partial().parse(req.body);
    const client = await prisma.client.update({
      where: { id: req.params.id },
      data: { ...data, email: data.email || undefined },
    });
    await logActivity({
      clientId: client.id,
      userId: req.user?.id,
      type: "CLIENT_UPDATED",
      message: `Kunde "${client.name}" wurde aktualisiert`,
    });
    res.json(client);
  })
);

router.delete(
  "/:id",
  asyncHandler(async (req, res) => {
    await prisma.client.delete({ where: { id: req.params.id } });
    res.status(204).send();
  })
);

// Contacts
const contactSchema = z.object({
  name: z.string().min(1),
  role: z.string().optional(),
  email: z.string().email().optional().or(z.literal("")),
  phone: z.string().optional(),
  isPrimary: z.boolean().optional(),
});

router.post(
  "/:id/contacts",
  asyncHandler(async (req, res) => {
    const data = contactSchema.parse(req.body);
    const contact = await prisma.contact.create({
      data: { ...data, email: data.email || undefined, clientId: req.params.id },
    });
    res.status(201).json(contact);
  })
);

router.patch(
  "/:id/contacts/:contactId",
  asyncHandler(async (req, res) => {
    const data = contactSchema.partial().parse(req.body);
    const contact = await prisma.contact.update({
      where: { id: req.params.contactId },
      data: { ...data, email: data.email || undefined },
    });
    res.json(contact);
  })
);

router.delete(
  "/:id/contacts/:contactId",
  asyncHandler(async (req, res) => {
    await prisma.contact.delete({ where: { id: req.params.contactId } });
    res.status(204).send();
  })
);

export default router;
