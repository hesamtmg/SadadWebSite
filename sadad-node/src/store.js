// Tiny JSON-file order store. Fine for a demo or low volume; swap for a database in production.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

export class OrderStore {
  constructor(dir) {
    fs.mkdirSync(dir, { recursive: true });
    this.file = path.join(dir, 'orders.json');
    this.orders = fs.existsSync(this.file) ? JSON.parse(fs.readFileSync(this.file, 'utf8')) : [];
  }

  #save() {
    const tmp = `${this.file}.${process.pid}.tmp`;
    fs.writeFileSync(tmp, JSON.stringify(this.orders, null, 2));
    fs.renameSync(tmp, this.file);
  }

  create(fields) {
    const now = new Date().toISOString();
    const order = {
      id: crypto.randomBytes(8).toString('hex'), // public id used in URLs (not guessable)
      status: 'created',                          // created → pending → paid | failed | canceled
      bankOrderId: null,
      token: null,
      events: [],
      createdAt: now,
      updatedAt: now,
      ...fields,
    };
    this.orders.push(order);
    this.#save();
    return order;
  }

  get(id) {
    return this.orders.find((o) => o.id === id) ?? null;
  }

  findByToken(token) {
    return token ? this.orders.find((o) => o.token === token) ?? null : null;
  }

  findByBankOrderId(bankOrderId) {
    return bankOrderId ? this.orders.find((o) => o.bankOrderId === String(bankOrderId)) ?? null : null;
  }

  /** Numeric OrderId for Sadad, unique per terminal; a new one for every payment attempt. */
  nextBankOrderId() {
    let id;
    do {
      id = `${Date.now()}${crypto.randomInt(10, 99)}`;
    } while (this.findByBankOrderId(id));
    return id;
  }

  update(id, changes, event) {
    const order = this.get(id);
    if (!order) return null;
    Object.assign(order, changes, { updatedAt: new Date().toISOString() });
    if (event) order.events.push({ at: order.updatedAt, ...event });
    this.#save();
    return order;
  }

  list() {
    return [...this.orders].sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  }
}
