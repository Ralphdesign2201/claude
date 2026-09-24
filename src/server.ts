import app from "./app";

const port = process.env.PORT ? Number(process.env.PORT) : 4000;

app.listen(port, () => {
  console.log(`🚀 CRM-Backend läuft auf http://localhost:${port}`);
});
