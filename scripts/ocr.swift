import CoreGraphics
import CoreImage
import Foundation
import PDFKit
import Vision

enum OCRError: Error {
    case missingPath
    case invalidPDF
    case cannotRenderPage(Int)
}

func renderPageImage(_ page: PDFPage, targetWidth: CGFloat = 1600) -> CGImage? {
    let bounds = page.bounds(for: .mediaBox)
    guard bounds.width > 0, bounds.height > 0 else {
        return nil
    }
    let scale = targetWidth / bounds.width
    let width = max(Int(bounds.width * scale), 1)
    let height = max(Int(bounds.height * scale), 1)

    let colorSpace = CGColorSpaceCreateDeviceRGB()
    guard let context = CGContext(
        data: nil,
        width: width,
        height: height,
        bitsPerComponent: 8,
        bytesPerRow: 0,
        space: colorSpace,
        bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue
    ) else {
        return nil
    }

    context.setFillColor(CGColor(gray: 1.0, alpha: 1.0))
    context.fill(CGRect(x: 0, y: 0, width: width, height: height))
    context.saveGState()
    context.translateBy(x: 0, y: CGFloat(height))
    context.scaleBy(x: scale, y: -scale)
    page.draw(with: .mediaBox, to: context)
    context.restoreGState()
    return context.makeImage()
}

func recognizeText(from image: CGImage) throws -> String {
    var recognized = [String]()
    let request = VNRecognizeTextRequest()
    request.recognitionLevel = .accurate
    request.usesLanguageCorrection = true

    let handler = VNImageRequestHandler(cgImage: image, options: [:])
    try handler.perform([request])

    let observations = request.results ?? []
    for observation in observations {
        guard let candidate = observation.topCandidates(1).first else {
            continue
        }
        recognized.append(candidate.string)
    }

    return recognized.joined(separator: "\n").trimmingCharacters(in: .whitespacesAndNewlines)
}

func extractText(from pdfPath: String) throws -> String {
    let url = URL(fileURLWithPath: pdfPath)
    guard let document = PDFDocument(url: url) else {
        throw OCRError.invalidPDF
    }

    var pages = [String]()
    let pageCount = document.pageCount

    for pageIndex in 0..<pageCount {
        guard let page = document.page(at: pageIndex) else {
            continue
        }

        let embedded = (page.string ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        if !embedded.isEmpty {
            pages.append(embedded)
            continue
        }

        guard let image = renderPageImage(page) else {
            throw OCRError.cannotRenderPage(pageIndex + 1)
        }
        let ocrText = try recognizeText(from: image)
        pages.append(ocrText)
    }

    return pages.joined(separator: "\n\n\u{000C}\n\n").trimmingCharacters(in: .whitespacesAndNewlines)
}

do {
    guard CommandLine.arguments.count >= 2 else {
        throw OCRError.missingPath
    }
    let path = CommandLine.arguments[1]
    let text = try extractText(from: path)
    FileHandle.standardOutput.write(Data(text.utf8))
    exit(EXIT_SUCCESS)
} catch OCRError.missingPath {
    fputs("Usage: swift scripts/ocr.swift /path/to/file.pdf\n", stderr)
    exit(EXIT_FAILURE)
} catch OCRError.invalidPDF {
    fputs("Unable to open PDF file.\n", stderr)
    exit(EXIT_FAILURE)
} catch OCRError.cannotRenderPage(let page) {
    fputs("Unable to render page \(page) for OCR.\n", stderr)
    exit(EXIT_FAILURE)
} catch {
    fputs("OCR failed: \(error.localizedDescription)\n", stderr)
    exit(EXIT_FAILURE)
}
