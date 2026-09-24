import { PrismaClient } from "@prisma/client";
import bcrypt from "bcryptjs";

const prisma = new PrismaClient();

async function main() {
  const passwordHash = await bcrypt.hash("admin1234", 10);
  const admin = await prisma.user.upsert({
    where: { email: "admin@example.com" },
    update: {},
    create: { name: "Admin", email: "admin@example.com", passwordHash, role: "ADMIN" },
  });

  const client = await prisma.client.upsert({
    where: { id: "seed-client-1" },
    update: {},
    create: {
      id: "seed-client-1",
      name: "Anna Beispiel",
      company: "Beispiel GmbH",
      email: "anna@beispiel.de",
      phone: "+49 170 1234567",
      website: "https://beispiel.de",
      city: "Berlin",
      country: "Deutschland",
      status: "ACTIVE",
      ownerId: admin.id,
      tags: "webdesign,stammkunde",
    },
  });

  const project = await prisma.project.upsert({
    where: { id: "seed-project-1" },
    update: {},
    create: {
      id: "seed-project-1",
      clientId: client.id,
      ownerId: admin.id,
      name: "Website Relaunch",
      description: "Kompletter Relaunch der Firmenwebsite inkl. CMS",
      status: "IN_PROGRESS",
      budget: 6500,
      hourlyRate: 90,
    },
  });

  const existingTasks = await prisma.task.count({ where: { projectId: project.id } });
  if (existingTasks === 0) {
    await prisma.task.createMany({
      data: [
        { projectId: project.id, title: "Wireframes erstellen", status: "DONE", priority: "HIGH", position: 0 },
        { projectId: project.id, title: "Design-Konzept", status: "IN_PROGRESS", priority: "HIGH", position: 1 },
        { projectId: project.id, title: "CMS Integration", status: "OPEN", priority: "MEDIUM", position: 2 },
      ],
    });
  }

  const invoiceExists = await prisma.invoice.findUnique({ where: { number: "RE-2026-0001" } });
  if (!invoiceExists) {
    await prisma.invoice.create({
      data: {
        number: "RE-2026-0001",
        clientId: client.id,
        projectId: project.id,
        status: "SENT",
        taxRate: 19,
        items: {
          create: [
            { description: "Konzeption & Design", quantity: 1, unitPrice: 2500, position: 0 },
            { description: "Entwicklung", quantity: 20, unitPrice: 90, position: 1 },
          ],
        },
      },
    });
  }

  console.log("✅ Seed abgeschlossen. Login: admin@example.com / admin1234");
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
