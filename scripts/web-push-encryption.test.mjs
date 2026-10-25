import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = `require ${JSON.stringify(path.join(root, 'src/Notifications/WebPushService.php'))};
$service = (new ReflectionClass(SevillaMatrix\\Notifications\\WebPushService::class))->newInstanceWithoutConstructor();
$payload = json_encode(['title' => 'IB1234 · cinta 8', 'body' => 'Cambio de cinta ñ €', 'tag' => 'matrix-outbox-7'], JSON_UNESCAPED_UNICODE);
echo base64_encode($service->encryptPayload($payload, $argv[1], $argv[2]));`;

const ecdh = crypto.createECDH('prime256v1');
const userPublic = ecdh.generateKeys();
const authSecret = crypto.randomBytes(16);
const hkdf = (ikm, salt, info, length) => Buffer.from(crypto.hkdfSync('sha256', ikm, salt, info, length));

for (let round = 0; round < 20; round++) {
  const body = Buffer.from(execFileSync('php', ['-r', php, userPublic.toString('base64url'), authSecret.toString('base64url')]).toString(), 'base64');
  const salt = body.subarray(0, 16);
  const recordSize = body.readUInt32BE(16);
  const idLength = body[20];
  const serverPublic = body.subarray(21, 21 + idLength);
  const record = body.subarray(21 + idLength);
  const ikm = hkdf(ecdh.computeSecret(serverPublic), authSecret, Buffer.concat([Buffer.from('WebPush: info\0'), userPublic, serverPublic]), 32);
  const decipher = crypto.createDecipheriv('aes-128-gcm', hkdf(ikm, salt, Buffer.from('Content-Encoding: aes128gcm\0'), 16), hkdf(ikm, salt, Buffer.from('Content-Encoding: nonce\0'), 12));
  decipher.setAuthTag(record.subarray(record.length - 16));
  const plain = Buffer.concat([decipher.update(record.subarray(0, record.length - 16)), decipher.final()]);
  if (recordSize !== 4096 || idLength !== 65 || plain[plain.length - 1] !== 2) throw new Error('Cabecera aes128gcm o delimitador incorrecto');
  const notice = JSON.parse(plain.subarray(0, -1).toString('utf8'));
  if (notice.tag !== 'matrix-outbox-7' || !notice.body.includes('€')) throw new Error('Contenido push inesperado');
}
console.log('Web Push: 20 avisos cifrados en PHP y descifrados según RFC 8291');
