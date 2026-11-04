"""Additive AM contracts. Existing portal endpoints and policies remain untouched.

Only the server-side portal HMAC secret authorizes these routes. Never expose it
to Android. Customer opt-in, retail pricing and payment verification belong to AM.
"""
import hashlib
import hmac
import json
import time
from decimal import Decimal
from contextlib import contextmanager

from fastapi import HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field, ValidationError, model_validator

SCHEMA = """CREATE TABLE IF NOT EXISTS am_renewal_results (
    order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    reseller_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json LONGTEXT NOT NULL,
    refreshed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT UNSIGNED NOT NULL,
    INDEX am_reseller_line (reseller_id,line_id)
) ENGINE=InnoDB"""


class ContractError(ValueError):
    pass


class Input(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)


class Login(Input):
    username: str | None = Field(default=None, min_length=1, max_length=255)
    password: str | None = Field(default=None, min_length=1, max_length=255)
    activation_code: str | None = Field(default=None, min_length=1, max_length=100)

    @model_validator(mode="after")
    def one_method(self):
        if self.activation_code is not None:
            if self.username is not None or self.password is not None:
                raise ValueError("Choose one login method")
        elif self.username is None or self.password is None:
            raise ValueError("Credentials required")
        return self


class Owner(Input):
    reseller_id: str = Field(pattern=r"^[1-9][0-9]{0,17}$")


class Customer(Owner):
    line_id: str = Field(pattern=r"^[1-9][0-9]{0,17}$")


class ResellerSearch(Input):
    query: str = Field(default="", max_length=100)
    reseller_ids: list[str] = Field(default_factory=list, max_length=100)

    @model_validator(mode="after")
    def valid_ids(self):
        for value in self.reseller_ids:
            Owner(reseller_id=value)
        return self


class Quote(Customer):
    package_id: int = Field(ge=1, le=2147483647)


class Renew(Quote):
    order_id: str = Field(pattern=r"^[a-f0-9]{32}$")
    package_fingerprint: str = Field(pattern=r"^[a-f0-9]{64}$")
    line_fingerprint: str = Field(pattern=r"^[a-f0-9]{64}$")


class Result(Owner):
    order_id: str = Field(pattern=r"^[a-f0-9]{32}$")


class BouquetUpdate(Customer):
    bouquet_ids: list[int] = Field(max_length=2000)
    revision: str = Field(pattern=r"^[a-f0-9]{64}$")
    auth_revision: str = Field(pattern=r"^[a-f0-9]{64}$")


def unlimited(line):
    # An unactivated line also has no expiry; that is NOT an unlimited service.
    return int(line.get('activated') or 0) == 1 and int(line.get('exp_date') or 0) == 0


def bouquet_revision(line, package, allowed=None):
    return digest([str(line['id']), str(line['member_id']), str(line.get('package_id')),
                   unlimited(line), sorted(set(ids(line.get('bouquet')))),
                   sorted(set(allowed if allowed is not None else ids(package.get('bouquets')) if package else []))])


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode()).hexdigest()


def line_fingerprint(line):
    # Bouquet selections are free and may change independently; preserve the latest
    # selections at renewal. Relevant service changes require a fresh quote instead.
    return digest({key: str(line.get(key) or 0) for key in
                   ("id", "member_id", "package_id", "max_connections", "exp_date", "activated", "is_trial", "enabled", "duration", "duration_period")})


def package_fingerprint(package):
    return digest({key: str(package.get(key) if package.get(key) is not None else "") for key in
                   ("id", "package_name", "credits", "duration", "duration_period", "duration_value", "max_connections", "type", "enabled", "activated")})


def ids(raw):
    try:
        value = json.loads(raw or "[]")
        if not isinstance(value, list) or any(not (type(item) is int and item >= 0) and not (isinstance(item, str) and item.isascii() and item.isdigit()) for item in value):
            raise ValueError()
        return [int(item) for item in value]
    except (TypeError, ValueError):
        raise ContractError("Invalid bouquet data; no changes were made.") from None


PACKAGE_COLUMNS = "id,package_name,credits,duration,duration_period,duration_value,max_connections,bouquets,groups,type,enabled,activated"
LINE_COLUMNS = "id,member_id,username,password,activation_code,package_id,max_connections,exp_date,activated,is_trial,enabled,bouquet,duration,duration_period"


class Service:
    def __init__(self, worker):
        self.w = worker

    def resellers(self, request):
        # users is the panel reseller directory; customer logins live in customers.
        with self.w["panel_db_cursor"]() as (cur, _):
            if request.reseller_ids:
                cur.execute("SELECT id,username,status FROM users WHERE id IN (" +
                            ",".join(["%s"] * len(request.reseller_ids)) + ") ORDER BY username,id",
                            tuple(request.reseller_ids))
            else:
                cur.execute("SELECT id,username,status FROM users WHERE LOCATE(%s,username)>0 ORDER BY username,id LIMIT 201",
                            (request.query.strip(),))
            rows = cur.fetchall()
        return {"ok": True, "more": len(rows) > 200, "resellers": [
            {"id": str(row["id"]), "username": str(row["username"]), "enabled": int(row.get("status") or 0) == 1}
            for row in rows[:200]]}

    @contextmanager
    def transaction(self):
        with self.w["panel_db_cursor"](autocommit=False) as (cur, conn):
            try:
                yield cur, conn
            except BaseException:
                try:
                    conn.rollback()
                except Exception:
                    pass
                raise

    def owner(self, cur, reseller_id, lock=False):
        cur.execute("SELECT id,username,credits,status FROM users WHERE id=%s" + (" FOR UPDATE" if lock else ""), (reseller_id,))
        owner = cur.fetchone()
        if not owner:
            raise ContractError("Reseller account is unavailable.")
        return owner

    def line(self, cur, owner, line_id, lock=False):
        cur.execute("SELECT " + LINE_COLUMNS + " FROM customers WHERE id=%s AND member_id=%s" + (" FOR UPDATE" if lock else ""), (line_id, owner["id"]))
        line = cur.fetchone()
        if not line or int(owner.get("status") or 0) != 1 or int(line.get("enabled") or 0) != 1:
            raise ContractError("Customer or owning reseller is unavailable.")
        return line

    def package(self, cur, owner, package_id, lock=False):
        cur.execute("SELECT " + PACKAGE_COLUMNS + " FROM users_packages WHERE id=%s" + (" FOR UPDATE" if lock else ""), (package_id,))
        package = cur.fetchone()
        group_id, overrides = self.w["_reseller_package_scope"](cur, owner["username"])
        if (not package or int(package.get("enabled") or 0) != 1 or int(package.get("activated") or 0) != 1
                or not self.w["_package_allowed"](package, group_id, overrides)):
            raise ContractError("Package is not offered to this reseller.")
        return package

    def package_view(self, package):
        return {"id": int(package["id"]), "name": str(package.get("package_name") or ""),
                "price_credits": str(package.get("credits") or 0), "duration": int(package.get("duration") or 0),
                "duration_period": str(package.get("duration_period") or "days"),
                "duration_hours": int(self.w["_duration_to_days"](package.get("duration") or 0, package.get("duration_period")) * 24),
                "max_connections": int(package.get("max_connections") or 1),
                "package_type": str(package.get("type") if package.get("type") is not None else ""),
                "bouquets": ids(package.get("bouquets")), "worker_fingerprint": package_fingerprint(package)}

    def customer_view(self, cur, owner, line):
        cur.execute("SELECT " + PACKAGE_COLUMNS + " FROM users_packages WHERE id=%s", (line.get("package_id"),))
        package = cur.fetchone()
        selected = ids(line.get("bouquet"))
        allowed = ids(package.get("bouquets")) if package else []
        cur.execute("SELECT id,bouquet_name FROM bouquets")
        names = {int(row["id"]): str(row["bouquet_name"]) for row in cur.fetchall()}
        if unlimited(line):
            allowed = sorted(names)
        # Match the reseller panel's online-session definition, scoped to this
        # already authorized customer. Never expose IPs or session/device data.
        active_connections = None
        try:
            cur.execute("SELECT COUNT(*) AS active_connections FROM connections c "
                        "JOIN customers cu ON cu.id=c.user_id "
                        "WHERE cu.id=%s AND cu.member_id=%s AND c.closed=0", (line['id'], owner['id']))
            active_connections = int(cur.fetchone()['active_connections'])
        except Exception:
            # Usage telemetry failure must not fabricate zero or block account access.
            pass
        revision = hmac.new(self.w["settings"].shared_secret.encode(),
                            ("am-auth-revision:" + json.dumps([line.get(key) for key in ("id", "member_id", "username", "password", "activation_code")], separators=(",", ":"))).encode(), hashlib.sha256).hexdigest()
        return {"ok": True, "reseller_id": str(owner["id"]), "reseller_username": owner["username"], "auth_revision": revision,
                "line": {"line_id": str(line["id"]), "username": line["username"],
                         "package_id": int(line["package_id"]) if line.get("package_id") else None,
                         "package_name": package.get("package_name") if package else None,
                         "max_connections": int(line.get("max_connections") or 1),
                         "active_connections": active_connections,
                         "expire_at": int(line.get("exp_date") or 0), "activated": bool(line.get("activated")),
                         "is_trial": bool(line.get("is_trial")), "line_fingerprint": line_fingerprint(line),
                         "bouquet_revision": bouquet_revision(line, package, allowed),
                         "bouquets": [{"id": bid, "name": names.get(bid, str(bid)), "selected": bid in selected, "available": bid in allowed}
                                      for bid in sorted(set(selected + allowed))]}}

    def update_bouquets(self, request):
        desired = sorted(set(request.bouquet_ids))
        if any(bid < 0 for bid in desired) or len(desired) != len(request.bouquet_ids):
            raise ContractError('Invalid bouquet selection.')
        with self.transaction() as (cur, conn):
            owner = self.owner(cur, request.reseller_id, lock=True)
            line = self.line(cur, owner, request.line_id, lock=True)
            if not unlimited(line):
                self.package(cur, owner, int(line.get('package_id') or 0), lock=True)
            view = self.customer_view(cur, owner, line)
            if not hmac.compare_digest(view['auth_revision'], request.auth_revision):
                raise ContractError('Service login changed. Sign in again.')
            current = sorted(set(ids(line.get('bouquet'))))
            allowed = {b['id'] for b in view['line']['bouquets'] if b['available']}
            if set(desired) - (allowed | set(current)):
                raise ContractError('A selected bouquet is not permitted for this package.')
            if current != desired:
                if not hmac.compare_digest(view['line']['bouquet_revision'], request.revision):
                    raise ContractError('Bouquets or package changed. Refresh before editing again.')
                cur.execute('UPDATE customers SET bouquet=%s,updated=NOW() WHERE id=%s AND member_id=%s',
                            (json.dumps(desired), line['id'], owner['id']))
            conn.commit()
        # Absolute assignment is replay-safe. Retry a lost response or failed native
        # refresh with the same selection; no credit, expiry or package changes.
        try:
            refreshed = bool(self.w['_overcast_refresh_customer'](int(request.line_id)))
        except Exception:
            refreshed = False
        result = self.customer(Customer(reseller_id=request.reseller_id, line_id=request.line_id))
        result['refresh_pending'] = not refreshed
        return result

    def login(self, request):
        with self.w["panel_db_cursor"]() as (cur, _):
            if request.activation_code is not None:
                cur.execute("SELECT " + LINE_COLUMNS + " FROM customers WHERE activation_code=%s LIMIT 2", (request.activation_code,))
            else:
                cur.execute("SELECT " + LINE_COLUMNS + " FROM customers WHERE username=%s AND password=%s LIMIT 2", (request.username, request.password))
            matches = cur.fetchall()
            # SQL collation can be case-insensitive; compare the actual credentials
            # exactly, and fail closed on ambiguous customer rows.
            if len(matches) != 1:
                raise ContractError("Customer login could not be verified.")
            line = matches[0]
            owner = self.owner(cur, str(line["member_id"]))
            line = self.line(cur, owner, str(line["id"]))
            pairs = [(line.get("activation_code"), request.activation_code)] if request.activation_code is not None else [(line.get("username"), request.username), (line.get("password"), request.password)]
            if any(not isinstance(actual, str) or not hmac.compare_digest(actual.encode(), expected.encode()) for actual, expected in pairs):
                raise ContractError("Customer login could not be verified.")
            return self.customer_view(cur, owner, line)

    def customer(self, request):
        with self.w["panel_db_cursor"]() as (cur, _):
            owner = self.owner(cur, request.reseller_id)
            return self.customer_view(cur, owner, self.line(cur, owner, request.line_id))

    def catalogue(self, request):
        with self.w["panel_db_cursor"]() as (cur, _):
            owner = self.owner(cur, request.reseller_id)
            if int(owner.get("status") or 0) != 1:
                raise ContractError("Reseller account is unavailable.")
            group_id, overrides = self.w["_reseller_package_scope"](cur, owner["username"])
            cur.execute("SELECT " + PACKAGE_COLUMNS + " FROM users_packages WHERE enabled=1 AND activated=1 ORDER BY package_name")
            return {"ok": True, "packages": [self.package_view(p) for p in cur.fetchall() if self.w["_package_allowed"](p, group_id, overrides)]}

    def validate_renewal(self, cur, line, package):
        price = Decimal(str(package.get("credits") or 0))
        if not price.is_finite() or price < 0 or int(package.get("duration") or 0) <= 0:
            raise ContractError("Invalid renewal package.")
        if package.get("type") is None or int(package["type"]) == 1:
            raise ContractError("AM customer checkout requires a non-trial renewal package.")
        if not line.get("exp_date") and line.get("activated"):
            raise ContractError("Unlimited lines cannot be renewed.")
        if price == 0:
            cur.execute("SELECT type FROM users_packages WHERE id=%s", (line.get("package_id"),))
            source = cur.fetchone()
            if int(line.get("is_trial") or 0) == 1 or (source and int(source.get("type") or 0) == 1):
                raise ContractError("Trial conversion requires a paid, non-trial package.")
        return price

    def quote(self, request):
        with self.w["panel_db_cursor"]() as (cur, _):
            owner = self.owner(cur, request.reseller_id)
            line = self.line(cur, owner, request.line_id)
            package = self.package(cur, owner, request.package_id)
            price = self.validate_renewal(cur, line, package)
            if Decimal(str(owner.get("credits") or 0)) < price:
                raise ContractError("Reseller has insufficient renewal credits.")
            return {"ok": True, "reseller_id": request.reseller_id, "line_id": request.line_id,
                    "line_fingerprint": line_fingerprint(line), "package": self.package_view(package)}

    def renew(self, request):
        request_hash = digest(request.model_dump())
        with self.transaction() as (cur, conn):
            # Same lock order as the existing panel: reseller, line, package.
            owner = self.owner(cur, request.reseller_id, lock=True)
            cur.execute("SELECT * FROM am_renewal_results WHERE order_id=%s FOR UPDATE", (request.order_id,))
            previous = cur.fetchone()
            if previous:
                if str(previous["reseller_id"]) != request.reseller_id or previous["request_hash"] != request_hash:
                    raise ContractError("Order ID was already used for another renewal request.")
                conn.commit()
                return self.result(Result(reseller_id=request.reseller_id, order_id=request.order_id))
            line = self.line(cur, owner, request.line_id, lock=True)
            package = self.package(cur, owner, request.package_id, lock=True)
            price = self.validate_renewal(cur, line, package)
            if not hmac.compare_digest(line_fingerprint(line), request.line_fingerprint) or not hmac.compare_digest(package_fingerprint(package), request.package_fingerprint):
                raise ContractError("Service or package changed after the quote. Review the paid order; do not charge again.")
            balance = Decimal(str(owner.get("credits") or 0))
            if balance < price:
                raise ContractError("Reseller has insufficient renewal credits.")
            target_trial = self.w["enforce_trial_policy"](cur, line, package, "renew")
            if target_trial:
                raise ContractError("Trial renewals are not customer purchases.")
            old_package = None
            if not line.get("exp_date") and line.get("package_id"):
                cur.execute("SELECT duration,duration_period FROM users_packages WHERE id=%s", (line["package_id"],))
                old_package = cur.fetchone()
            _, expiry = self.w["_renewal_window"](line, package, old_package)
            bouquets = ids(line.get("bouquet"))
            cur.execute("UPDATE customers SET exp_date=%s,package_id=%s,duration=%s,duration_period=%s,duration_value=%s,max_connections=%s,bouquet=%s,is_trial=0,enabled=1,activated=1,updated=NOW() WHERE id=%s AND member_id=%s",
                        (int(expiry.timestamp()), request.package_id, package.get("duration") or 0, package.get("duration_period") or "days",
                         package.get("duration_value") or 0, package.get("max_connections") or 1, json.dumps(bouquets), line["id"], owner["id"]))
            self.w["_set_credits"](cur, owner["id"], balance - price, -price, balance, "line_renew", owner["id"], "customer", line["id"])
            result = {"ok": True, "order_id": request.order_id, "reseller_id": request.reseller_id,
                      "line_id": request.line_id, "package_id": request.package_id,
                      "expire_at": expiry.strftime("%Y-%m-%dT%H:%M:%SZ"), "credits_charged": str(price),
                      "new_balance": str(balance - price), "state": "applied_refresh_pending"}
            cur.execute("INSERT INTO am_renewal_results (order_id,reseller_id,line_id,request_hash,result_json,refreshed,created_at) VALUES (%s,%s,%s,%s,%s,0,%s)",
                        (request.order_id, request.reseller_id, request.line_id, request_hash, json.dumps(result), int(time.time())))
            conn.commit()
        # Commit the idempotency record, credits and expiry together BEFORE native
        # refresh. A lost response or refresh error can only repeat refresh.
        return self.result(Result(reseller_id=request.reseller_id, order_id=request.order_id))

    def result(self, request):
        with self.w["panel_db_cursor"]() as (cur, _):
            cur.execute("SELECT * FROM am_renewal_results WHERE order_id=%s AND reseller_id=%s", (request.order_id, request.reseller_id))
            row = cur.fetchone()
        if not row:
            return {"ok": True, "state": "not_applied", "order_id": request.order_id}
        result = json.loads(row["result_json"])
        if row["refreshed"]:
            return dict(result, state="applied")
        try:
            refreshed = self.w["_overcast_refresh_customer"](int(row["line_id"]))
            if refreshed:
                with self.transaction() as (cur, conn):
                    cur.execute("UPDATE am_renewal_results SET refreshed=1 WHERE order_id=%s AND reseller_id=%s", (request.order_id, request.reseller_id))
                    conn.commit()
                return dict(result, state="applied")
        except Exception:
            pass
        return dict(result, state="applied_refresh_pending")


def register(app, worker):
    service = Service(worker)

    def add(path, model, operation):
        async def handler(request: Request):
            # Never reflect Pydantic validation errors: those can contain login secrets.
            raw = b""
            async for chunk in request.stream():
                raw += chunk
                if len(raw) > 16384:
                    raise HTTPException(413, "Request too large")
            if not worker["verify_signature"](raw, request.headers.get("X-Portal-Signature"), request.headers.get("X-Portal-Timestamp")):
                raise HTTPException(401, "Invalid signature")
            try:
                # PHP encodes an empty associative request as []; accept it only
                # for the read-only, signed capabilities endpoint.
                if path == '/am/capabilities' and raw.strip() == b'[]':
                    raw = b'{}'
                value = model.model_validate_json(raw)
            except ValidationError:
                raise HTTPException(422, "Invalid AM request") from None
            try:
                return operation(value)
            except (ContractError, worker["TrialPolicyError"]) as error:
                return {"ok": False, "error": str(error)}
            except Exception:
                # An uncertain commit must be reconciled by order ID, never blindly
                # repeated through the old non-idempotent /renew-line endpoint.
                raise HTTPException(503, "AM worker operation could not be confirmed; retain the same order ID") from None
        app.add_api_route(path, handler, methods=["POST"], name="am_" + path.rsplit("/", 1)[-1])

    add("/am/customer-auth", Login, service.login)
    add("/am/admin-resellers", ResellerSearch, service.resellers)
    add("/am/capabilities", Input, lambda _: {"ok": True, "version": 1, "authoritative_ownership": True, "idempotent_renewal": True, "bouquet_editing": True, "unlimited_bouquets": True})
    add("/am/customer", Customer, service.customer)
    add("/am/customer-bouquets", BouquetUpdate, service.update_bouquets)
    add("/am/packages", Owner, service.catalogue)
    add("/am/renew-quote", Quote, service.quote)
    add("/am/renew", Renew, service.renew)
    add("/am/renew-result", Result, service.result)
