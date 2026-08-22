<?php

namespace Livewire\Features\SupportFileUploads;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\WithFileUploads;
use Livewire\Component;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Livewire;

class ChunkedUploadsBrowserTest extends \Tests\BrowserTestCase
{
    public static function tweakApplicationHook()
    {
        return function () {
            // Small chunks so the 1MB test fixture uploads as many chunks...
            config([
                'livewire.temporary_file_upload.chunk_size' => 65536,
                'livewire.temporary_file_upload.chunk_threshold' => 65536,
            ]);
        };
    }

    public function test_files_over_the_chunk_threshold_upload_in_chunks_and_reassemble_correctly()
    {
        Storage::persistentFake('tmp-for-tests');

        Livewire::visit(new class extends Component {
            use WithFileUploads;

            public $file;

            function mount()
            {
                Storage::disk('tmp-for-tests')->deleteDirectory('files');
            }

            function save()
            {
                $this->file->storeAs('files', 'assembled.jpg', 'tmp-for-tests');
            }

            function render() { return <<<'HTML'
            <div>
                <input type="file" wire:model="file" dusk="upload">

                <div wire:loading wire:target="file">uploading...</div>

                @if ($file)
                    <span dusk="filename">{{ $file->getClientOriginalName() }}</span>
                @endif

                <button wire:click="save" dusk="save">Save</button>
            </div>
            HTML; }
        })
        ->attach('@upload', __DIR__ . '/browser_test_image_big.jpg')
        ->waitFor('@filename')
        ->assertSeeIn('@filename', 'browser_test_image_big.jpg')
        ->tap(function ($browser) {
            // Prove the file actually travelled through the chunk endpoint. A
            // regression that quietly fell back to the plain form POST would
            // otherwise still pass the sha256 check below...
            $chunkRequests = $browser->script(
                "return window.performance.getEntriesByType('resource').filter(r => r.name.includes('upload-chunk')).length"
            )[0];

            $this->assertGreaterThan(1, $chunkRequests, 'Expected the 1MB file to upload as multiple chunk requests.');
        })
        ->waitForLivewire()
        ->click('@save')
        ->tap(function () {
            $this->assertEquals(
                hash_file('sha256', __DIR__ . '/browser_test_image_big.jpg'),
                hash('sha256', Storage::disk('tmp-for-tests')->get('files/assembled.jpg'))
            );
        });
    }

    public function test_chunked_upload_succeeds_when_the_proxys_forwarded_https_origin_is_not_trusted()
    {
        // Signs under https, then swaps https:// for http:// on the URL,
        // simulating a proxy Laravel doesn't trust to report its real origin.
        $this->beforeServingApplication(function () {
            Storage::persistentFake('tmp-for-tests');

            config([
                'livewire.temporary_file_upload.chunk_size' => 65536,
                'livewire.temporary_file_upload.chunk_threshold' => 65536,
            ]);

            GenerateSignedUploadUrlFacade::swap(new class extends GenerateSignedUploadUrl {
                public function forChunks()
                {
                    URL::forceScheme('https');
                    $url = parent::forChunks();
                    URL::forceScheme(null);

                    return preg_replace('#^https://#', 'http://', $url);
                }
            });

            Livewire::component('https-signed-chunked-upload', HttpsSignedChunkedUploadComponent::class);

            Route::get('/https-signed-chunked-upload', HttpsSignedChunkedUploadComponent::class)->middleware('web');
        });

        $this->browse(function ($browser) {
            $browser->visit('/https-signed-chunked-upload')
                ->attach('@upload', __DIR__ . '/browser_test_image_big.jpg')
                ->waitFor('@filename')
                ->assertSeeIn('@filename', 'browser_test_image_big.jpg')
                ->tap(function ($browser) {
                    $chunkRequests = $browser->script(
                        "return window.performance.getEntriesByType('resource').filter(r => r.name.includes('upload-chunk')).length"
                    )[0];

                    $this->assertGreaterThan(1, $chunkRequests, 'Expected the 1MB file to upload as multiple chunk requests.');
                })
            ;
        });
    }
}

class HttpsSignedChunkedUploadComponent extends Component
{
    use WithFileUploads;

    public $file;

    function render() { return <<<'HTML'
    <div>
        <input type="file" wire:model="file" dusk="upload">

        @if ($file)
            <span dusk="filename">{{ $file->getClientOriginalName() }}</span>
        @endif
    </div>
    HTML; }
}
