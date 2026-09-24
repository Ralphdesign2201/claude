import { Request } from "express";

export function parsePagination(req: Request) {
  const page = Math.max(1, parseInt((req.query.page as string) || "1", 10) || 1);
  const pageSize = Math.min(100, Math.max(1, parseInt((req.query.pageSize as string) || "20", 10) || 20));
  return { page, pageSize, skip: (page - 1) * pageSize, take: pageSize };
}

export function paginated<T>(items: T[], total: number, page: number, pageSize: number) {
  return {
    items,
    meta: {
      total,
      page,
      pageSize,
      totalPages: Math.max(1, Math.ceil(total / pageSize)),
    },
  };
}
