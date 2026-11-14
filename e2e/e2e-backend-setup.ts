// Creates the sandbox reseller account used by WHMCS, adds test TLDs and prints the API key.
import { db, users, apiKeys, tldPricing, tldPeriodPrices } from "./src/db";
import { generateApiKey } from "./src/middleware/auth";
import { eq } from "drizzle-orm";
import { nanoid } from "nanoid";

const email = "reseller@e2e.test";
let user = await db.query.users.findFirst({ where: eq(users.email, email) });
if (!user) {
  await db.insert(users).values({
    id: nanoid(),
    email,
    passwordHash: await Bun.password.hash("e2e-password"),
    firstName: "E2E",
    lastName: "Reseller",
    emailVerified: true,
    doNotBill: true,
  });
  user = await db.query.users.findFirst({ where: eq(users.email, email) });
}

// TLDs the default seed lacks: .ai (2-year minimum term, priced per term like OpenProvider)
// and .it (nic.it RegCode). Prices = public live prices of domain-reseller-api.de.
const extraTlds = [
  { tld: "ai", registerPrice: "97.56", renewPrice: "97.56", transferPrice: "97.57", restorePrice: "329.26", minYears: 2, maxYears: 10, provider: "openprovider" },
  { tld: "it", registerPrice: "6.11", renewPrice: "6.11", transferPrice: "6.11", restorePrice: "6.11", minYears: 1, maxYears: 1, provider: "vautron" },
];
for (const t of extraTlds) {
  if (!(await db.query.tldPricing.findFirst({ where: eq(tldPricing.tld, t.tld) }))) {
    await db.insert(tldPricing).values({ id: nanoid(), ...t });
  }
}
const aiTerms = [[2, "195.12"], [3, "292.68"], [4, "390.25"], [5, "487.80"], [6, "585.36"], [7, "682.93"], [8, "780.49"], [9, "878.05"], [10, "975.61"]] as const;
if (!(await db.query.tldPeriodPrices.findFirst({ where: eq(tldPeriodPrices.tld, "ai") }))) {
  await db.insert(tldPeriodPrices).values(aiTerms.map(([years, price]) => ({ id: nanoid(), tld: "ai", years, registerPrice: price, renewPrice: price })));
}

const { key, prefix, hash } = await generateApiKey();
await db.insert(apiKeys).values({ id: nanoid(), userId: user!.id, name: "whmcs-e2e", keyHash: hash, keyPrefix: prefix, permissions: ["*"] });
console.log("API_KEY=" + key);
process.exit(0);
