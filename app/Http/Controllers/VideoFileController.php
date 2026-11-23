<?php

namespace App\Http\Controllers;

use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\DeletePin;
use App\Support\LibraryCleaner;
use App\Support\MediaUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoFileController extends Controller
{
    public function destroy(Request $request, Video $catalogVideo, LibraryCleaner $cleaner, DeletePin $deletePin): VideoResource|JsonResponse
    {
        $deletePin->authorize($request);

        try {
            $removal = $cleaner->unloadVideo($catalogVideo);
        } catch (MediaUnavailable) {
            return response()->json(['message' => 'The media disk is not available.'], 503);
        }

        if ($removal->hasLeftovers()) {
            return response()->json(['message' => 'Some of the video files could not be removed.'], 500);
        }

        $video = Video::catalog()
            ->withDownloadState()
            ->with(VideoResource::CHANNEL_RELATION)
            ->findOrFail($catalogVideo->id);

        return new VideoResource($video);
    }
}
