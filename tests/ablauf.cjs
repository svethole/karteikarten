// Browser-Test des Hauptablaufs gegen einen laufenden Server (Standard: http://127.0.0.1:8099).
// Aufruf: node tests/ablauf.cjs [basisadresse] [bilderordner]
const { chromium } = require("playwright");
const BASIS = process.argv[2] || "http://127.0.0.1:8099";
const BILDER = process.argv[3] || "";
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1300, height: 900 } });
  const fehler = [];
  p.on("pageerror", (e) => fehler.push("JS: " + e.message));
  p.on("console", (m) => m.type() === "error" && fehler.push("Konsole: " + m.text()));
  const schritt = async (name, fn) => { await fn(); if (BILDER) await p.screenshot({ path: `${BILDER}/${name}.png`, fullPage: true }); console.log("✓", name); };
  const warte = (ms = 400) => p.waitForTimeout(ms);

  await schritt("01-neues-projekt", async () => {
    await p.goto(BASIS + "/#/projekt/neu"); await warte(800);
    await p.fill('input[name="name"]', "Anatomie Test");
    await p.fill('input[name="symbol"]', "🧠");
    await p.fill('input[name="beschreibung"]', "Knochen, Muskeln und Nerven");
    await p.click('[data-farbe="#7c3aed"]');
    await p.click('button:has-text("Projekt anlegen")'); await warte(1200);
  });
  const pid = Number((await p.evaluate(() => location.hash)).match(/projekt\/(\d+)/)[1]);

  await schritt("02-designer", async () => {
    await p.goto(`${BASIS}/#/designer/neu?projekt=${pid}`); await warte(800);
    await p.fill("[data-name]", "Fachbegriff");
    // Baustein „Merke-Kasten“ per Ziehen auf die Rückseite
    const quelle = await p.locator('[data-neu="kasten"]').boundingBox();
    const ziel = await p.locator('[data-ablage="hinten"]').boundingBox();
    await p.mouse.move(quelle.x + 20, quelle.y + 10); await p.mouse.down();
    await p.mouse.move(quelle.x + 60, quelle.y + 40, { steps: 5 });
    await p.mouse.move(ziel.x + 40, ziel.y + ziel.height - 10, { steps: 10 });
    await p.mouse.up(); await warte();
    const n = await p.locator('[data-ablage="hinten"] .baustein').count();
    if (n !== 5) throw new Error("Ziehen in den Designer hat nicht geklappt: " + n);
    await p.fill('[data-props] [data-p="titel"]', "Eselsbrücke");
    await p.click("[data-speichern]"); await warte(1000);
  });

  await schritt("03-karte-anlegen", async () => {
    await p.goto(`${BASIS}/#/notiz/neu?projekt=${pid}`); await warte(800);
    await p.selectOption("[data-typ]", { label: "Fachbegriff" }); await warte(600);
    await p.fill('[data-feld="Vorderseite"]', "Was ist der **Musculus deltoideus**?");
    await p.fill('[data-feld="Rückseite"]', "Der Deltamuskel der Schulter\n- Abduktion im Schultergelenk\n- Ansatz: Tuberositas deltoidea");
    await p.fill('[data-feld="Notiz"]', "Delta = dreieckig wie Δ");
    await p.fill("[data-tags]", "schulter muskel"); await warte(300);
    await p.click("[data-und-weiter]"); await warte(1000);
    await p.selectOption("[data-typ]", { label: "Lückentext" }); await warte(600);
    await p.fill('[data-feld="Text"]', "Der {{c1::Nervus radialis}} versorgt die {{c2::Extensoren}} des Unterarms.");
    await warte(300);
    await p.click('button[type="submit"].haupt'); await warte(1200);
  });

  await schritt("04-lernen", async () => {
    await p.goto(`${BASIS}/#/lernen/projekt/${pid}`); await warte(1000);
    for (let i = 0; i < 3; i++) { await p.keyboard.press("Space"); await warte(300); await p.keyboard.press("3"); await warte(300); }
    await p.keyboard.press("Space"); await warte(400);
  });
  await schritt("05-projekt", async () => { await p.goto(`${BASIS}/#/projekt/${pid}`); await warte(1200); });
  await schritt("06-handy-uebersicht", async () => { await p.setViewportSize({ width: 390, height: 844 }); await p.goto(BASIS + "/#/"); await warte(1200); });

  await b.close();
  if (fehler.length) { console.error(fehler.join("\n")); process.exit(1); }
  console.log("Alles gut.");
})().catch((e) => { console.error("✗", e.message); process.exit(1); });
