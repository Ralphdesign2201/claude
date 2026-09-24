import { prisma } from "../lib/prisma";

export async function logActivity(opts: {
  clientId?: string;
  projectId?: string;
  userId?: string;
  type: string;
  message: string;
}) {
  await prisma.activity.create({
    data: {
      clientId: opts.clientId,
      projectId: opts.projectId,
      userId: opts.userId,
      type: opts.type,
      message: opts.message,
    },
  });
}
