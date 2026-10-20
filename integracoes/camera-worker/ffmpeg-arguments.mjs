/** Monta uma captura JPEG única sem expor URL e credenciais nos argumentos do processo. */
export function createFfmpegArguments() {
  return [
    "-hide_banner",
    "-loglevel", "error",
    "-nostdin",
    "-protocol_whitelist", "file,crypto,data,rtsp,tcp",
    "-f", "concat",
    "-safe", "0",
    "-i", "/dev/stdin",
    "-an",
    "-sn",
    "-dn",
    "-frames:v", "1",
    "-q:v", "5",
    "-c:v", "mjpeg",
    "-f", "image2pipe",
    "-flush_packets", "1",
    "pipe:1",
  ];
}

/** Entrega a URL como lista ffconcat pela entrada padrão do FFmpeg, fora do argv. */
export function createFfmpegInputList(inputUrl) {
  const url = String(inputUrl);
  if (/[\r\n]/.test(url)) throw new Error("A URL RTSP contém quebra de linha inválida.");
  const escapedUrl = url.replaceAll("\\", "\\\\").replaceAll("'", "'\\''");
  return [
    "ffconcat version 1.0",
    `file '${escapedUrl}'`,
    "option rtsp_transport tcp",
    "option timeout 5000000",
    "option fflags nobuffer",
    "option flags low_delay",
    "option max_delay 100000",
    "",
  ].join("\n");
}
