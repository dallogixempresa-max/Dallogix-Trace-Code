#!/usr/bin/env python3
"""Servidor Modbus TCP mínimo para testes locais do TRACE.

Não representa o mapa do CLP de produção. Todos os valores começam zerados e
ficam apenas na memória do container.
"""

from __future__ import annotations

import logging
import os
import socketserver
import struct
from array import array


HOST = "0.0.0.0"
PORT = int(os.getenv("MODBUS_PORT", "1502"))
COILS = array("B", [0] * 64)
DISCRETE_INPUTS = array("B", [0] * 64)
HOLDING_REGISTERS = array("H", [0] * 64)
INPUT_REGISTERS = array("H", [0] * 64)


class ModbusError(ValueError):
    def __init__(self, code: int):
        self.code = code
        super().__init__(f"Modbus exception {code}")


def receive_exact(sock, count: int) -> bytes:
    """TCP é um fluxo: um recv pode retornar apenas parte do ADU."""
    data = bytearray()
    while len(data) < count:
        chunk = sock.recv(count - len(data))
        if not chunk:
            raise EOFError
        data.extend(chunk)
    return bytes(data)


def exception_response(function: int, code: int) -> bytes:
    return bytes([function | 0x80, code])


def pack_bits(values: array) -> bytes:
    result = bytearray((len(values) + 7) // 8)
    for index, value in enumerate(values):
        if value:
            result[index // 8] |= 1 << (index % 8)
    return bytes(result)


def read_bits(values: array, address: int, quantity: int) -> bytes:
    if quantity < 1 or quantity > 2000:
        raise ModbusError(3)
    if address < 0 or address + quantity > len(values):
        raise ModbusError(2)
    return pack_bits(values[address : address + quantity])


class ModbusHandler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        self.request.settimeout(3)
        while True:
            try:
                header = receive_exact(self.request, 7)
            except (EOFError, TimeoutError, ConnectionError):
                return
            transaction, protocol, length, unit = struct.unpack(">HHHB", header)
            if protocol != 0 or not 2 <= length <= 254:
                return
            try:
                body = receive_exact(self.request, length - 1)
            except (EOFError, TimeoutError, ConnectionError):
                return
            function = body[0]
            try:
                response_body = self.process(function, body[1:])
            except ModbusError as error:
                response_body = exception_response(function, error.code)
            except ValueError:
                response_body = exception_response(function, 3)
            response = struct.pack(">HHHB", transaction, 0, len(response_body) + 1, unit) + response_body
            try:
                self.request.sendall(response)
            except (TimeoutError, ConnectionError):
                return

    def process(self, function: int, payload: bytes) -> bytes:
        if function in (1, 2):
            if len(payload) != 4:
                raise ValueError
            address, quantity = struct.unpack(">HH", payload)
            values = COILS if function == 1 else DISCRETE_INPUTS
            bits = read_bits(values, address, quantity)
            logging.debug("read bits function=%s address=%s quantity=%s", function, address, quantity)
            return bytes([function, len(bits)]) + bits
        if function in (3, 4):
            if len(payload) != 4:
                raise ValueError
            address, quantity = struct.unpack(">HH", payload)
            values = HOLDING_REGISTERS if function == 3 else INPUT_REGISTERS
            if quantity < 1 or quantity > 125:
                raise ModbusError(3)
            if address + quantity > len(values):
                raise ModbusError(2)
            data = b"".join(struct.pack(">H", value) for value in values[address : address + quantity])
            logging.debug("read registers function=%s address=%s quantity=%s", function, address, quantity)
            return bytes([function, len(data)]) + data
        if function == 5:
            if len(payload) != 4:
                raise ValueError
            address, value = struct.unpack(">HH", payload)
            if value not in (0, 0xFF00):
                raise ModbusError(3)
            if address >= len(COILS):
                raise ModbusError(2)
            COILS[address] = 1 if value else 0
            logging.debug("coil[%s] = %s", address, COILS[address])
            return bytes([function]) + payload
        if function == 6:
            if len(payload) != 4:
                raise ValueError
            address, value = struct.unpack(">HH", payload)
            if address >= len(HOLDING_REGISTERS):
                raise ModbusError(2)
            HOLDING_REGISTERS[address] = value
            logging.debug("holding[%s] = %s", address, value)
            return bytes([function]) + payload
        raise ModbusError(1)


class ReusableServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    configured_level = os.getenv("MODBUS_LOG_LEVEL", "INFO").upper()
    logging.basicConfig(
        level=getattr(logging, configured_level, logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    with ReusableServer((HOST, PORT), ModbusHandler) as server:
        logging.info("Modbus virtual local listening on %s", PORT)
        server.serve_forever()
