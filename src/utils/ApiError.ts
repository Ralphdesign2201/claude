export class ApiError extends Error {
  status: number;
  details?: unknown;

  constructor(status: number, message: string, details?: unknown) {
    super(message);
    this.status = status;
    this.details = details;
  }

  static badRequest(message: string, details?: unknown) {
    return new ApiError(400, message, details);
  }

  static unauthorized(message = "Nicht authentifiziert") {
    return new ApiError(401, message);
  }

  static forbidden(message = "Kein Zugriff") {
    return new ApiError(403, message);
  }

  static notFound(message = "Nicht gefunden") {
    return new ApiError(404, message);
  }

  static conflict(message: string) {
    return new ApiError(409, message);
  }
}
