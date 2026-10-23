import { describe, expect, it } from "vitest";
import { hashOwnerCredential, isOwnerCredentialHash, verifyOwnerCredential } from "../src/auth/ownerCredential.js";
import { TEST_OWNER_PASSWORD, TEST_OWNER_PASSWORD_HASH } from "./oauthFixtures.js";

describe("site-owner OAuth credential hashes", () => {
  it("accepts a scrypt hash and verifies the owner credential", async () => {
    expect(isOwnerCredentialHash(TEST_OWNER_PASSWORD_HASH)).toBe(true);
    await expect(verifyOwnerCredential(TEST_OWNER_PASSWORD, TEST_OWNER_PASSWORD_HASH)).resolves.toBe(true);
    await expect(verifyOwnerCredential("wrong-password", TEST_OWNER_PASSWORD_HASH)).resolves.toBe(false);
  });

  it("rejects missing, malformed, and unsupported hashes", async () => {
    expect(isOwnerCredentialHash("")).toBe(false);
    expect(isOwnerCredentialHash("sha256$deadbeef")).toBe(false);
    expect(isOwnerCredentialHash(TEST_OWNER_PASSWORD_HASH.replace("16384", "1024"))).toBe(false);
    await expect(verifyOwnerCredential(TEST_OWNER_PASSWORD, "invalid")).resolves.toBe(false);
  });

  it("creates hashes with fresh salts by default", () => {
    const first = hashOwnerCredential(TEST_OWNER_PASSWORD);
    const second = hashOwnerCredential(TEST_OWNER_PASSWORD);

    expect(isOwnerCredentialHash(first)).toBe(true);
    expect(isOwnerCredentialHash(second)).toBe(true);
    expect(first).not.toBe(second);
  });
});
