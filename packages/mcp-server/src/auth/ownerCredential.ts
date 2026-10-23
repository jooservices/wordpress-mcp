import { randomBytes, scrypt, scryptSync, timingSafeEqual } from "node:crypto";

const SCRYPT_N = 16_384;
const SCRYPT_R = 8;
const SCRYPT_P = 1;
const KEY_LENGTH = 64;
const SALT_LENGTH = 16;

type ParsedCredentialHash = {
  salt: Buffer;
  digest: Buffer;
};

function parseOwnerCredentialHash(encoded: string): ParsedCredentialHash | undefined {
  const parts = encoded.split("$");
  if (parts.length !== 6 || parts[0] !== "scrypt") {
    return undefined;
  }

  if (parts[1] !== String(SCRYPT_N) || parts[2] !== String(SCRYPT_R) || parts[3] !== String(SCRYPT_P)) {
    return undefined;
  }

  const salt = Buffer.from(parts[4] ?? "", "base64url");
  const digest = Buffer.from(parts[5] ?? "", "base64url");
  if (
    salt.length !== SALT_LENGTH ||
    digest.length !== KEY_LENGTH ||
    salt.toString("base64url") !== parts[4] ||
    digest.toString("base64url") !== parts[5]
  ) {
    return undefined;
  }

  return { salt, digest };
}

export function isOwnerCredentialHash(encoded: string): boolean {
  return parseOwnerCredentialHash(encoded) !== undefined;
}

export function hashOwnerCredential(password: string, salt = randomBytes(SALT_LENGTH)): string {
  if (password.length === 0) {
    throw new Error("Owner credential must not be empty");
  }
  if (salt.length !== SALT_LENGTH) {
    throw new Error(`Owner credential salt must be ${SALT_LENGTH} bytes`);
  }

  const digest = scryptSync(password, salt, KEY_LENGTH, {
    N: SCRYPT_N,
    r: SCRYPT_R,
    p: SCRYPT_P,
  });

  return ["scrypt", SCRYPT_N, SCRYPT_R, SCRYPT_P, salt.toString("base64url"), digest.toString("base64url")].join("$");
}

export async function verifyOwnerCredential(password: string, encodedHash: string): Promise<boolean> {
  if (password.length === 0 || Buffer.byteLength(password, "utf8") > 1024) {
    return false;
  }

  const parsed = parseOwnerCredentialHash(encodedHash);
  if (!parsed) {
    return false;
  }

  const actual = await new Promise<Buffer>((resolve, reject) => {
    scrypt(
      password,
      parsed.salt,
      KEY_LENGTH,
      { N: SCRYPT_N, r: SCRYPT_R, p: SCRYPT_P },
      (error, derivedKey) => {
        if (error) {
          reject(error);
          return;
        }
        resolve(derivedKey);
      },
    );
  });

  return timingSafeEqual(actual, parsed.digest);
}
