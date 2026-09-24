import "dotenv/config";
import express from "express";
import cors from "cors";
import helmet from "helmet";
import morgan from "morgan";
import cookieParser from "cookie-parser";
import path from "path";
import rateLimit from "express-rate-limit";

import authRoutes from "./routes/auth.routes";
import clientRoutes from "./routes/clients.routes";
import projectRoutes from "./routes/projects.routes";
import taskRoutes from "./routes/tasks.routes";
import timeEntryRoutes from "./routes/timeEntries.routes";
import invoiceRoutes from "./routes/invoices.routes";
import contractRoutes from "./routes/contracts.routes";
import noteRoutes from "./routes/notes.routes";
import documentRoutes from "./routes/documents.routes";
import dashboardRoutes from "./routes/dashboard.routes";
import userRoutes from "./routes/users.routes";

import { requireAuth } from "./middleware/auth";
import { errorHandler, notFoundHandler } from "./middleware/errorHandler";

const app = express();

app.use(helmet({ crossOriginResourcePolicy: false }));
app.use(cors({ origin: process.env.CORS_ORIGIN || "*", credentials: true }));
app.use(morgan("dev"));
app.use(express.json({ limit: "5mb" }));
app.use(cookieParser());
app.use("/uploads", express.static(path.join(process.cwd(), "uploads")));

const apiLimiter = rateLimit({ windowMs: 15 * 60 * 1000, max: 500 });
app.use("/api", apiLimiter);

app.get("/health", (_req, res) => res.json({ status: "ok" }));

app.use("/api/auth", authRoutes);
app.use("/api/clients", requireAuth, clientRoutes);
app.use("/api/projects", requireAuth, projectRoutes);
app.use("/api/tasks", requireAuth, taskRoutes);
app.use("/api/time-entries", requireAuth, timeEntryRoutes);
app.use("/api/invoices", requireAuth, invoiceRoutes);
app.use("/api/contracts", requireAuth, contractRoutes);
app.use("/api/notes", requireAuth, noteRoutes);
app.use("/api/documents", requireAuth, documentRoutes);
app.use("/api/dashboard", requireAuth, dashboardRoutes);
app.use("/api/users", requireAuth, userRoutes);

app.use(notFoundHandler);
app.use(errorHandler);

export default app;
