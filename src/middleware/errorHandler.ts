import { NextFunction, Request, Response } from "express";
import { ZodError } from "zod";
import { ApiError } from "../utils/ApiError";

export function notFoundHandler(req: Request, res: Response) {
  res.status(404).json({ error: "Route nicht gefunden" });
}

export function errorHandler(err: unknown, req: Request, res: Response, next: NextFunction) {
  if (err instanceof ZodError) {
    return res.status(400).json({ error: "Validierungsfehler", details: err.flatten() });
  }

  if (err instanceof ApiError) {
    return res.status(err.status).json({ error: err.message, details: err.details });
  }

  const anyErr = err as any;
  if (anyErr?.code === "P2002") {
    return res.status(409).json({ error: "Eintrag existiert bereits", fields: anyErr.meta?.target });
  }
  if (anyErr?.code === "P2025") {
    return res.status(404).json({ error: "Datensatz nicht gefunden" });
  }

  console.error(err);
  res.status(500).json({ error: "Interner Serverfehler" });
}
