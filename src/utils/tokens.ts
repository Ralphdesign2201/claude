import jwt, { SignOptions } from "jsonwebtoken";
import { AuthPayload } from "../middleware/auth";

export function signToken(payload: { id: string; email: string; role: string }) {
  const options: SignOptions = {
    expiresIn: (process.env.JWT_EXPIRES_IN || "7d") as SignOptions["expiresIn"],
  };
  return jwt.sign(payload, process.env.JWT_SECRET as string, options);
}
